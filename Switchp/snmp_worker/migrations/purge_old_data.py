#!/usr/bin/env python3
"""
One-time data purge: shrink port_change_history and device_polling_data.

Operations performed:
  1. port_change_history  — delete rows older than 3 days.
  2. device_polling_data  — delete all duplicate rows, keep only the
                            single most-recent row per device_id.
                            (Should leave exactly one row per monitored
                            device, e.g. 38 rows for 38 devices.)
  3. OPTIMIZE TABLE on both to reclaim disk space and rebuild indexes.

Safe to re-run: each DELETE is idempotent.

Usage:
    cd Switchp/snmp_worker
    python migrations/purge_old_data.py [--days N]

    --days N   Days of port_change_history to keep (default: 3)
"""

import sys
import argparse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import create_engine, text
from config.config_loader import Config


def _table_size_mb(conn, table: str) -> float:
    """Return approximate table size in MB (data + indexes)."""
    result = conn.execute(text("""
        SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE() AND table_name = :t
    """), {"t": table})
    row = result.fetchone()
    return float(row[0]) if row and row[0] is not None else 0.0


def _count(conn, table: str) -> int:
    return conn.execute(text(f"SELECT COUNT(*) FROM `{table}`")).scalar()


def main():
    parser = argparse.ArgumentParser(description="Purge old port_change_history and device_polling_data")
    parser.add_argument("--days", type=int, default=3,
                        help="Days of port_change_history to keep (default: 3)")
    args = parser.parse_args()

    print("=" * 65)
    print("DATA PURGE — port_change_history + device_polling_data")
    print("=" * 65)

    config = Config()
    engine = create_engine(config.get_database_url(), echo=False)

    # ── 1. port_change_history ────────────────────────────────────────────
    print(f"\n→ Step 1: port_change_history — keep last {args.days} day(s)")

    with engine.begin() as conn:
        pch_before = _count(conn, "port_change_history")
        pch_size_before = _table_size_mb(conn, "port_change_history")
        print(f"  Before: {pch_before:,} rows  ({pch_size_before:.2f} MB)")

        # Delete in batches of 10 000 to avoid long table locks
        total_deleted_pch = 0
        while True:
            result = conn.execute(text("""
                DELETE FROM port_change_history
                WHERE change_timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)
                LIMIT 10000
            """), {"days": args.days})
            total_deleted_pch += result.rowcount
            if result.rowcount == 0:
                break

        pch_after = _count(conn, "port_change_history")
        print(f"  Deleted: {total_deleted_pch:,} rows")
        print(f"  After:   {pch_after:,} rows  (before OPTIMIZE)")

    # ── 2. device_polling_data ────────────────────────────────────────────
    print("\n→ Step 2: device_polling_data — keep latest row per device_id")

    with engine.begin() as conn:
        dpd_before = _count(conn, "device_polling_data")
        dpd_size_before = _table_size_mb(conn, "device_polling_data")
        print(f"  Before: {dpd_before:,} rows  ({dpd_size_before:.2f} MB)")

        # Delete all but MAX(id) per device_id in batches
        total_deleted_dpd = 0
        while True:
            result = conn.execute(text("""
                DELETE dpd
                FROM device_polling_data dpd
                INNER JOIN (
                    SELECT device_id, MAX(id) AS max_id
                    FROM device_polling_data
                    GROUP BY device_id
                ) latest
                  ON dpd.device_id = latest.device_id
                 AND dpd.id < latest.max_id
                LIMIT 10000
            """))
            total_deleted_dpd += result.rowcount
            if result.rowcount == 0:
                break

        dpd_after = _count(conn, "device_polling_data")
        print(f"  Deleted: {total_deleted_dpd:,} rows")
        print(f"  After:   {dpd_after:,} rows  (before OPTIMIZE)")

    # ── 3. OPTIMIZE TABLE — reclaim disk space ────────────────────────────
    print("\n→ Step 3: OPTIMIZE TABLE (reclaims freed disk space)...")
    print("  Note: This may take a few minutes on large tables.")

    with engine.begin() as conn:
        conn.execute(text("OPTIMIZE TABLE port_change_history"))
        print("  ✓ OPTIMIZE TABLE port_change_history done")

    with engine.begin() as conn:
        conn.execute(text("OPTIMIZE TABLE device_polling_data"))
        print("  ✓ OPTIMIZE TABLE device_polling_data done")

    # ── 4. Summary ────────────────────────────────────────────────────────
    with engine.connect() as conn:
        pch_size_after = _table_size_mb(conn, "port_change_history")
        dpd_size_after = _table_size_mb(conn, "device_polling_data")

    print("\n" + "=" * 65)
    print("SUMMARY")
    print("=" * 65)
    print(f"  port_change_history:")
    print(f"    Rows:  {pch_before:>10,}  →  {pch_after:,}")
    print(f"    Size:  {pch_size_before:>8.2f} MB  →  {pch_size_after:.2f} MB  "
          f"(saved {pch_size_before - pch_size_after:.2f} MB)")
    print(f"  device_polling_data:")
    print(f"    Rows:  {dpd_before:>10,}  →  {dpd_after:,}")
    print(f"    Size:  {dpd_size_before:>8.2f} MB  →  {dpd_size_after:.2f} MB  "
          f"(saved {dpd_size_before - dpd_size_after:.2f} MB)")
    total_saved = (pch_size_before + dpd_size_before) - (pch_size_after + dpd_size_after)
    print(f"  Total disk saved: {total_saved:.2f} MB")
    print("=" * 65)
    return 0


if __name__ == "__main__":
    sys.exit(main())
