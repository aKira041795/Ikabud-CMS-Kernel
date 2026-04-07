INSERT INTO gm_users (email, password, first_name, last_name, role, is_active)
SELECT
	'admin@guidance.local',
	'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
	'Guidance',
	'Admin',
	'admin',
	1
FROM DUAL
WHERE NOT EXISTS (
	SELECT 1
	FROM gm_users
	WHERE role = 'admin' AND deleted_at IS NULL
	LIMIT 1
);

INSERT INTO gm_colleges (code, name, description, is_active, sort_order)
VALUES ('GENERAL', 'General', 'Default college placeholder for new guidance installations.', 1, 10)
ON DUPLICATE KEY UPDATE
	name = VALUES(name),
	description = VALUES(description),
	is_active = VALUES(is_active),
	sort_order = VALUES(sort_order),
	updated_at = NOW();

INSERT INTO gm_appointment_types (code, name, description, duration_minutes, color, requires_case, is_public, is_active, sort_order)
VALUES
	('individual', 'Individual Session', 'One-on-one student guidance session.', 30, '#2563eb', 0, 1, 1, 10),
	('followup', 'Follow-up Session', 'Scheduled follow-up meeting for an existing concern.', 30, '#0f766e', 1, 1, 1, 20),
	('career', 'Career Guidance', 'Career planning and advising session.', 45, '#7c3aed', 0, 1, 1, 30),
	('group', 'Group Session', 'Small group guidance or counseling session.', 60, '#d97706', 0, 0, 1, 40),
	('parent', 'Parent Conference', 'Parent or guardian consultation.', 45, '#dc2626', 1, 0, 1, 50),
	('crisis', 'Crisis Intervention', 'Urgent counseling or intervention session.', 60, '#b91c1c', 1, 0, 1, 60)
ON DUPLICATE KEY UPDATE
	name = VALUES(name),
	description = VALUES(description),
	duration_minutes = VALUES(duration_minutes),
	color = VALUES(color),
	requires_case = VALUES(requires_case),
	is_public = VALUES(is_public),
	is_active = VALUES(is_active),
	sort_order = VALUES(sort_order),
	updated_at = NOW();

INSERT INTO gm_settings (setting_key, setting_value, setting_type, description, is_system)
VALUES
	(
		'appointment_settings',
		'{"max_booking_days_ahead":14,"default_duration_minutes":30,"buffer_minutes":5}',
		'json',
		'Default appointment booking settings for public guidance requests.',
		1
	),
	(
		'working_hours',
		'{"monday":{"start":"08:00","end":"17:00"},"tuesday":{"start":"08:00","end":"17:00"},"wednesday":{"start":"08:00","end":"17:00"},"thursday":{"start":"08:00","end":"17:00"},"friday":{"start":"08:00","end":"17:00"}}',
		'json',
		'Default office working hours for slot generation.',
		1
	),
	(
		'school_info',
		'{"name":"Guidance Office","booking_intro":"Book a guidance appointment using the available public form."}',
		'json',
		'Baseline school information used by the public guidance booking page.',
		1
	)
ON DUPLICATE KEY UPDATE
	setting_value = VALUES(setting_value),
	setting_type = VALUES(setting_type),
	description = VALUES(description);
