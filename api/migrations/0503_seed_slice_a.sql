-- Slice A permissions: placement.manage (every placement, its change history, the satisfaction results and the
-- CSV exports). Deny by default: only CPDC.
INSERT INTO role_permission (organisation_id, role, permission, scope) VALUES
    (1, 'superadmin', 'placement.manage', 'all'),
    (1, 'admin', 'placement.manage', 'all');
