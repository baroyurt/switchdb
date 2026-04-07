#!/usr/bin/env python3
"""
Migration: add environmental monitoring columns to snmp_devices table.

Adds the following columns if they do not already exist:
  - fan_status    VARCHAR(20)  — 'OK' | 'WARNING' | 'CRITICAL' | 'N/A'
  - temperature_c FLOAT        — degrees Celsius
  - poe_nominal_w INT          — nominal PoE budget in Watts
  - poe_consumed_w INT         — current PoE draw in Watts

This migration is safe to run multiple times (idempotent).

Usage:
    cd snmp_worker
    python migrations/add_fan_temp_poe_columns.py
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
    print("SNMP Worker — Add Fan/Temp/PoE Columns Migration")
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
    COLUMNS = {
        'fan_status':    'VARCHAR(20)',
        'temperature_c': 'FLOAT',
        'poe_nominal_w': 'INT',
        'poe_consumed_w':'INT',
    }

    try:
        inspector = inspect(engine)
        if not inspector.has_table(TABLE):
            print(f"✗ Table '{TABLE}' does not exist — run create_tables.py first.")
            return 1

        missing = [c for c in COLUMNS if not check_column_exists(inspector, TABLE, c)]

        if not missing:
            print("✓ All columns already exist — no migration needed.")
            print()
            print("=" * 60)
            print("Already up to date.")
            print("=" * 60)
            return 0

        print(f"→ Missing columns: {', '.join(missing)}")
        print()
        print("→ Adding missing columns…")

        with engine.connect() as conn:
            for col in missing:
                sql = f"ALTER TABLE {TABLE} ADD COLUMN {col} {COLUMNS[col]}"
                try:
                    conn.execute(text(sql))
                    conn.commit()
                    print(f"  ✓ Added: {col} {COLUMNS[col]}")
                except Exception as e:
                    if "Duplicate column" in str(e) or "already exists" in str(e):
                        print(f"  • {col} already exists (skipped)")
                    else:
                        print(f"  ✗ Failed to add {col}: {e}")
                        raise

        print()
        print("✓ Migration completed successfully!")
        print()
        print("=" * 60)
        print("Migration completed successfully!")
        print("=" * 60)
        return 0

    except Exception as e:
        print()
        print(f"✗ Migration failed: {e}")
        print()
        print("=" * 60)
        print("Migration failed!")
        print("=" * 60)
        return 1


if __name__ == "__main__":
    sys.exit(run_migration())
