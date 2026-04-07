TYPE=VIEW
query=select `switchdb`.`migration_history`.`id` AS `id`,`switchdb`.`migration_history`.`migration_name` AS `migration_name`,`switchdb`.`migration_history`.`migration_type` AS `migration_type`,`switchdb`.`migration_history`.`applied_at` AS `applied_at`,`switchdb`.`migration_history`.`error_message` AS `error_message`,`switchdb`.`migration_history`.`execution_time_ms` AS `execution_time_ms` from `switchdb`.`migration_history` where `switchdb`.`migration_history`.`success` = 0 order by `switchdb`.`migration_history`.`applied_at` desc
md5=b85d0b5c5cc20fccf23ce831183a037b
updatable=1
algorithm=0
definer_user=root
definer_host=localhost
suid=2
with_check_option=0
timestamp=0001771439455548615
create-version=2
source=SELECT \n    id,\n    migration_name,\n    migration_type,\n    applied_at,\n    error_message,\n    execution_time_ms\nFROM migration_history\nWHERE success = 0\nORDER BY applied_at DESC;
client_cs_name=utf8mb4
connection_cl_name=utf8mb4_general_ci
view_body_utf8=select `switchdb`.`migration_history`.`id` AS `id`,`switchdb`.`migration_history`.`migration_name` AS `migration_name`,`switchdb`.`migration_history`.`migration_type` AS `migration_type`,`switchdb`.`migration_history`.`applied_at` AS `applied_at`,`switchdb`.`migration_history`.`error_message` AS `error_message`,`switchdb`.`migration_history`.`execution_time_ms` AS `execution_time_ms` from `switchdb`.`migration_history` where `switchdb`.`migration_history`.`success` = 0 order by `switchdb`.`migration_history`.`applied_at` desc
mariadb-version=100432
