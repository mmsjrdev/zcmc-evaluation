-- DEV ONLY. Never import this on the live (InfinityFree) site.
-- Creates one test account per role so you can see every menu and dashboard.
-- Password for all of them:  Temp12345!
-- (forced@test.local must change it at first sign-in, to test the forced change.)
SET @h = '$2y$10$Lz3.PK9tGhI1.viKfGCrbuoROeqxsGElGQ0/8K.mfIl9rTdqZIqni';
INSERT INTO users (email, password_hash, full_name, employee_id, department_id, `position`, business_name, role, status, requested_role, must_change_password) VALUES
('mms@test.local',         @h, 'Maria Santos',    NULL,    NULL, 'MMS Staff',  NULL,                   'mm_staff',   'active',  NULL,        0),
('nurse@test.local',       @h, 'Helen Nurse',     NULL,    NULL, 'Head Nurse', NULL,                   'head_nurse', 'active',  NULL,        0),
('eval@test.local',        @h, 'Eddie Evaluator', 'E-001', 1,    'Nurse II',   NULL,                   'evaluator',  'active',  'evaluator', 0),
('head@test.local',        @h, 'Hector Head',     'E-002', 1,    'Dept Head',  NULL,                   'head',       'active',  'head',      0),
('pendinghead@test.local', @h, 'Penny Pending',   'E-003', 1,    'Nurse',      NULL,                   'evaluator',  'pending', 'head',      0),
('supplier@test.local',    @h, 'Sam Supplier',    NULL,    NULL, 'Owner',      'ABC Medical Supplies', 'supplier',   'active',  NULL,        0),
('forced@test.local',      @h, 'Fred Forced',     NULL,    NULL, 'Owner',      'XYZ Pharma',           'supplier',   'active',  NULL,        1);
