TYPE=VIEW
query=select `switchdb`.`migration_history`.`migration_type` AS `migration_type`,count(0) AS `total_count`,sum(case when `switchdb`.`migration_history`.`success` = 1 then 1 else 0 end) AS `success_count`,sum(case when `switchdb`.`migration_history`.`success` = 0 then 1 else 0 end) AS `failed_count`,avg(`switchdb`.`migration_history`.`execution_time_ms`) AS `avg_execution_time_ms`,max(`switchdb`.`migration_history`.`applied_at`) AS `last_applied` from `switchdb`.`migration_history` group by `switchdb`.`migration_history`.`migration_type`
md5=d2c688474505ced182352a4ce5ee070d
updatable=0
algorithm=0
definer_user=root
definer_host=localhost
suid=2
with_check_option=0
timestamp=0001771439455539570
create-version=2
source=SELECT \n    migration_type,\n    COUNT(*) as total_count,\n    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,\n    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_count,\n    AVG(execution_time_ms) as avg_execution_time_ms,\n    MAX(applied_at) as last_applied\nFROM migration_history\nGROUP BY migration_type;
client_cs_name=utf8mb4
connection_cl_name=utf8mb4_general_ci
view_body_utf8=select `switchdb`.`migration_history`.`migration_type` AS `migration_type`,count(0) AS `total_count`,sum(case when `switchdb`.`migration_history`.`success` = 1 then 1 else 0 end) AS `success_count`,sum(case when `switchdb`.`migration_history`.`success` = 0 then 1 else 0 end) AS `failed_count`,avg(`switchdb`.`migration_history`.`execution_time_ms`) AS `avg_execution_time_ms`,max(`switchdb`.`migration_history`.`applied_at`) AS `last_applied` from `switchdb`.`migration_history` group by `switchdb`.`migration_history`.`migration_type`
mariadb-version=100432
