#!/usr/bin/env python3
"""
Migration: add cpu_1min column to snmp_devices table.

Adds the following column if it does not already exist:
  - cpu_1min  INT  — CPU load % averaged over the last 1 minute (0-100)

Collected by the SNMP worker from C9200L/C9300L switches via the
OLD-CISCO-CPU-MIB OID 1.3.6.1.4.1.9.2.1.57.0 (lcpu1MinAvg).

This migration is safe to run multiple times (idempotent).

Usage:
    cd snmp_worker
    python migrations/add_cpu_1min_column.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import create_engine, text, inspect
from config.config_loader import Config


def check_column_exists(inspector, table_name: str, column_name: str) -> bool:
    columns = [col['name'] for col in inspector.get_columns(table_name)]
    return column_name in columns


def run_migration() -> int:
    print("=" * 60)
    print("SNMP Worker — Add cpu_1min Column Migration")
    print("=" * 60)
    print()

    try:
        config = Config()
        print("✓ Configuration loaded")
        print(f"  Database : {config.database.name}")
        print(f"  Host     : {config.database.host}:{config.database.port}")
        print(f"  User     : {config.database.user}")
        print()
    except Exception as e:
        print(f"✗ Failed to load configuration: {e}")
        return 1

    try:
        db_url = config.get_database_url()
        engine = create_engine(db_url, echo=False)
        with engine.connect() as conn:
            version = conn.execute(text("SELECT VERSION()")).scalar()
        print(f"✓ Connected to MySQL {version}")
        print()
    except Exception as e:
        print(f"✗ Cannot connect to database: {e}")
        return 1

    TABLE = 'snmp_devices'

    try:
        inspector = inspect(engine)
        if not inspector.has_table(TABLE):
            print(f"✗ Table '{TABLE}' not found — run the main schema setup first.")
            return 1

        added = 0
        skipped = 0

        with engine.begin() as conn:
            if not check_column_exists(inspector, TABLE, 'cpu_1min'):
                conn.execute(text(
                    f"ALTER TABLE {TABLE} ADD COLUMN cpu_1min INT NULL"
                ))
                print("  + cpu_1min  INT  NULL  — added ✓")
                added += 1
            else:
                print("  · cpu_1min  — already exists, skipped")
                skipped += 1

        print()
        print(f"Migration complete: {added} column(s) added, {skipped} skipped.")
        return 0

    except Exception as e:
        print(f"✗ Migration failed: {e}")
        return 1


if __name__ == '__main__':
    sys.exit(run_migration())
