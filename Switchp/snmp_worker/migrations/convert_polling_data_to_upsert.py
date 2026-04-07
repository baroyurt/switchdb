#!/usr/bin/env python3
"""
Migration: convert device_polling_data from append-only to UPSERT (one row per device).

Steps:
  1. Add missing columns (system_name, system_description, system_uptime,
     system_contact, system_location, total_ports, active_ports, raw_data)
     if they do not already exist.
  2. Delete all but the most-recent row per device_id (keeps latest snapshot).
  3. Add UNIQUE INDEX on device_id so future inserts are rejected — the
     Python worker now uses UPSERT (update-in-place) instead of INSERT.
  4. Drop the old composite indexes that are no longer needed.

Safe to re-run (idempotent): each step checks before acting.

Usage:
    cd Switchp/snmp_worker
    python migrations/convert_polling_data_to_upsert.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import create_engine, text, inspect as sa_inspect
from config.config_loader import Config

TABLE = "device_polling_data"


def column_exists(conn, table: str, column: str) -> bool:
    result = conn.execute(
        text("SELECT COUNT(*) FROM information_schema.COLUMNS "
             "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"),
        {"t": table, "c": column}
    )
    return result.scalar() > 0


def index_exists(conn, table: str, index: str) -> bool:
    result = conn.execute(
        text("SELECT COUNT(*) FROM information_schema.STATISTICS "
             "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"),
        {"t": table, "i": index}
    )
    return result.scalar() > 0


def main():
    print("=" * 60)
    print("Migration: device_polling_data → UPSERT (one row per device)")
    print("=" * 60)

    config = Config()
    engine = create_engine(config.get_database_url(), echo=False)

    with engine.begin() as conn:
        # ── 1. Add missing columns ────────────────────────────────────────
        print("\n→ Step 1: Adding missing columns...")
        new_columns = {
            "system_name":        "VARCHAR(255)",
            "system_description": "TEXT",
            "system_uptime":      "BIGINT(20)",
            "system_contact":     "VARCHAR(255)",
            "system_location":    "VARCHAR(255)",
            "total_ports":        "INT(11) DEFAULT 0",
            "active_ports":       "INT(11) DEFAULT 0",
            "raw_data":           "TEXT",
        }
        for col, col_type in new_columns.items():
            if not column_exists(conn, TABLE, col):
                conn.execute(text(f"ALTER TABLE `{TABLE}` ADD COLUMN `{col}` {col_type}"))
                print(f"  ✓ Added column: {col}")
            else:
                print(f"  • Column already exists: {col}")

        # ── 2. Delete duplicate rows — keep only latest per device_id ─────
        print("\n→ Step 2: Deduplicating rows (keep latest per device_id)...")
        result = conn.execute(text(f"SELECT COUNT(*) FROM `{TABLE}`"))
        before = result.scalar()

        conn.execute(text(f"""
            DELETE dpd
            FROM `{TABLE}` dpd
            INNER JOIN (
                SELECT device_id, MAX(id) AS max_id
                FROM `{TABLE}`
                GROUP BY device_id
            ) latest ON dpd.device_id = latest.device_id
            WHERE dpd.id < latest.max_id
        """))

        result = conn.execute(text(f"SELECT COUNT(*) FROM `{TABLE}`"))
        after = result.scalar()
        deleted = before - after
        print(f"  ✓ Deleted {deleted:,} old rows  ({before:,} → {after:,})")

        # ── 3. Add UNIQUE INDEX on device_id ─────────────────────────────
        print("\n→ Step 3: Adding UNIQUE INDEX on device_id...")
        if not index_exists(conn, TABLE, "uq_polling_device"):
            conn.execute(text(
                f"ALTER TABLE `{TABLE}` ADD UNIQUE INDEX `uq_polling_device` (`device_id`)"
            ))
            print("  ✓ UNIQUE INDEX uq_polling_device created")
        else:
            print("  • UNIQUE INDEX uq_polling_device already exists")

        # ── 4. Drop old indexes no longer needed ─────────────────────────
        print("\n→ Step 4: Dropping obsolete indexes...")
        for old_idx in ("idx_polling_device_time", "idx_polling_timestamp"):
            if index_exists(conn, TABLE, old_idx):
                conn.execute(text(f"ALTER TABLE `{TABLE}` DROP INDEX `{old_idx}`"))
                print(f"  ✓ Dropped index: {old_idx}")
            else:
                print(f"  • Index not found (already removed): {old_idx}")

    print("\n" + "=" * 60)
    print("Migration completed successfully!")
    print(f"  device_polling_data is now capped at one row per device.")
    print("=" * 60)
    return 0


if __name__ == "__main__":
    sys.exit(main())
