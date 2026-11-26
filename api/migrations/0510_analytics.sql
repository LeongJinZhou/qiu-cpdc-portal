-- Slice C: CPDC analytics (university-wide dashboard, CSV reports, one person's 360 view). Deny by default: only
-- CPDC. No new index: the filters use ix_career_profile_tracker (organisation, faculty, graduation year) and the
-- per-person counts use each table's existing user key.
INSERT INTO role_permission (organisation_id, role, permission, scope) VALUES
    (1, 'superadmin', 'analytics.view', 'all'),
    (1, 'admin', 'analytics.view', 'all'),
    (1, 'superadmin', 'analytics.export', 'all'),
    (1, 'admin', 'analytics.export', 'all');
