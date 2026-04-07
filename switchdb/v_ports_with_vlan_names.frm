TYPE=VIEW
query=select `ps`.`id` AS `id`,`ps`.`device_id` AS `device_id`,`ps`.`port_number` AS `port_number`,`ps`.`port_name` AS `port_name`,`ps`.`port_alias` AS `port_alias`,`ps`.`port_description` AS `port_description`,`ps`.`admin_status` AS `admin_status`,`ps`.`oper_status` AS `oper_status`,`ps`.`vlan_id` AS `vlan_id`,`vn`.`vlan_name` AS `vlan_name`,`vn`.`description` AS `vlan_description`,`vn`.`color` AS `vlan_color`,`ps`.`mac_address` AS `mac_address`,`ps`.`port_speed` AS `port_speed`,`ps`.`port_type` AS `port_type` from (`switchdb`.`port_status_data` `ps` left join `switchdb`.`vlan_names` `vn` on(`ps`.`vlan_id` = `vn`.`vlan_id`)) order by `ps`.`device_id`,`ps`.`port_number`
md5=ab183815d683de2c10d40771852fe99b
updatable=0
algorithm=0
definer_user=root
definer_host=localhost
suid=2
with_check_option=0
timestamp=0001771056358934974
create-version=2
source=SELECT \n    ps.id,\n    ps.device_id,\n    ps.port_number,\n    ps.port_name,\n    ps.port_alias,\n    ps.port_description,\n    ps.admin_status,\n    ps.oper_status,\n    ps.vlan_id,\n    vn.vlan_name,\n    vn.description as vlan_description,\n    vn.color as vlan_color,\n    ps.mac_address,\n    ps.port_speed,\n    ps.port_type\nFROM port_status_data ps\nLEFT JOIN vlan_names vn ON ps.vlan_id = vn.vlan_id\nORDER BY ps.device_id, ps.port_number
client_cs_name=latin1
connection_cl_name=latin1_swedish_ci
view_body_utf8=select `ps`.`id` AS `id`,`ps`.`device_id` AS `device_id`,`ps`.`port_number` AS `port_number`,`ps`.`port_name` AS `port_name`,`ps`.`port_alias` AS `port_alias`,`ps`.`port_description` AS `port_description`,`ps`.`admin_status` AS `admin_status`,`ps`.`oper_status` AS `oper_status`,`ps`.`vlan_id` AS `vlan_id`,`vn`.`vlan_name` AS `vlan_name`,`vn`.`description` AS `vlan_description`,`vn`.`color` AS `vlan_color`,`ps`.`mac_address` AS `mac_address`,`ps`.`port_speed` AS `port_speed`,`ps`.`port_type` AS `port_type` from (`switchdb`.`port_status_data` `ps` left join `switchdb`.`vlan_names` `vn` on(`ps`.`vlan_id` = `vn`.`vlan_id`)) order by `ps`.`device_id`,`ps`.`port_number`
mariadb-version=100432
