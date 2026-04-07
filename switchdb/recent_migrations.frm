TYPE=VIEW
query=select `switchdb`.`migration_history`.`id` AS `id`,`switchdb`.`migration_history`.`migration_name` AS `migration_name`,`switchdb`.`migration_history`.`migration_type` AS `migration_type`,`switchdb`.`migration_history`.`applied_at` AS `applied_at`,`switchdb`.`migration_history`.`success` AS `success`,`switchdb`.`migration_history`.`execution_time_ms` AS `execution_time_ms`,case when `switchdb`.`migration_history`.`success` = 1 then \'SUCCESS\' else \'FAILED\' end AS `status` from `switchdb`.`migration_history` order by `switchdb`.`migration_history`.`applied_at` desc limit 50
md5=033630228fda083465d6313e5baa55ad
updatable=0
algorithm=0
definer_user=root
definer_host=localhost
suid=2
with_check_option=0
timestamp=0001771439455544971
create-version=2
source=SELECT \n    id,\n    migration_name,\n    migration_type,\n    applied_at,\n    success,\n    execution_time_ms,\n    CASE \n        WHEN success = 1 THEN \'SUCCESS\'\n        ELSE \'FAILED\'\n    END as status\nFROM migration_history\nORDER BY applied_at DESC\nLIMIT 50;
client_cs_name=utf8mb4
connection_cl_name=utf8mb4_general_ci
view_body_utf8=select `switchdb`.`migration_history`.`id` AS `id`,`switchdb`.`migration_history`.`migration_name` AS `migration_name`,`switchdb`.`migration_history`.`migration_type` AS `migration_type`,`switchdb`.`migration_history`.`applied_at` AS `applied_at`,`switchdb`.`migration_history`.`success` AS `success`,`switchdb`.`migration_history`.`execution_time_ms` AS `execution_time_ms`,case when `switchdb`.`migration_history`.`success` = 1 then \'SUCCESS\' else \'FAILED\' end AS `status` from `switchdb`.`migration_history` order by `switchdb`.`migration_history`.`applied_at` desc limit 50
mariadb-version=100432
