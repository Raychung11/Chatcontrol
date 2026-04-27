-- AiServe Shared WhatsApp Inbox - default seed data
-- Default super admin password: ChangeMe@123  (please change after first login)

INSERT INTO `companies` (`id`,`name`,`api_version`,`brand_color`,`timezone`,`status`)
VALUES (1,'AiServe / SLV Group','v21.0','#25D366','Asia/Kuala_Lumpur','active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `departments` (`id`,`company_id`,`name`,`status`) VALUES
  (1,1,'General','active'),
  (2,1,'Sales','active'),
  (3,1,'Support','active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Default super admin (password: ChangeMe@123)
-- Hash generated with PHP password_hash(...,PASSWORD_BCRYPT)
INSERT INTO `users`
  (`id`,`company_id`,`department_id`,`name`,`email`,`phone`,`password_hash`,`role`,`status`)
VALUES
  (1,1,1,'Super Admin','admin@aiserve.local',NULL,
   '$2y$12$rX41zMxvmlcuSAfOotA4zOIXp/eq2bs3kGOM/U9.eJP6ebo6SU5Iu',
   'super_admin','active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
