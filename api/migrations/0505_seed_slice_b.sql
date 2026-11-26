-- Slice B permissions and the Student Characteristics Self-Assessment. Deny by default: pending accounts get none.
-- home.view (the overview page), survey.take (answer the yearly University and Programme Survey),
-- survey.manage (open and close survey rounds, read results and comments, CSV export).

INSERT INTO role_permission (organisation_id, role, permission, scope) VALUES
    (1, 'superadmin', 'home.view', 'own'),
    (1, 'admin', 'home.view', 'own'),
    (1, 'staff', 'home.view', 'own'),
    (1, 'student', 'home.view', 'own'),
    (1, 'alumni', 'home.view', 'own'),
    (1, 'student', 'survey.take', 'own'),
    (1, 'superadmin', 'survey.manage', 'all'),
    (1, 'admin', 'survey.manage', 'all');

-- The legacy ACMS "Student Characteristics" form: nine traits rated 1-5 and two written commitments.
INSERT INTO assessment (organisation_id, title, description, answer_style, bands_json, reflection_prompts, status)
VALUES (
    1,
    'Student Characteristics Self-Assessment',
    'Rate yourself on the nine QIU student characteristics, then write how you will grow. Takes about five minutes.',
    'likert',
    '[{"min_percent": 0, "label": "Needs attention"}, {"min_percent": 50, "label": "Developing"}, {"min_percent": 70, "label": "Good"}, {"min_percent": 85, "label": "Excellent"}]',
    '["How will you commit to improving your characteristics?", "How can QIU assist you on this journey?"]',
    'published'
);
SET @characteristics = LAST_INSERT_ID();

INSERT INTO assessment_question (organisation_id, assessment_id, position, prompt) VALUES
    (1, @characteristics, 0, 'Resilience: I keep going and recover when things get hard.'),
    (1, @characteristics, 1, 'Growth Mindset / Lifelong Learning: I keep learning and see mistakes as a way to grow.'),
    (1, @characteristics, 2, 'Competitive Spirit: I set high standards and push myself to do my best.'),
    (1, @characteristics, 3, 'Proactive: I take the first step without waiting to be told.'),
    (1, @characteristics, 4, 'Ethical and Professional: I act honestly and keep my commitments.'),
    (1, @characteristics, 5, 'Socially Responsible: I care about my community and the people around me.'),
    (1, @characteristics, 6, 'Good Etiquette: I am polite and respectful in person and online.'),
    (1, @characteristics, 7, 'Affinity: I build good relationships and work well with others.'),
    (1, @characteristics, 8, 'Personal Skills: I manage my time, communicate clearly and solve problems.');

INSERT INTO assessment_option (question_id, position, label, points)
SELECT q.id, o.position, o.label, o.points
FROM assessment_question q
CROSS JOIN (
    SELECT 0 AS position, 'Very Poor' AS label, 1 AS points
    UNION ALL SELECT 1, 'Poor', 2
    UNION ALL SELECT 2, 'Average', 3
    UNION ALL SELECT 3, 'Good', 4
    UNION ALL SELECT 4, 'Excellent', 5
) o
WHERE q.assessment_id = @characteristics;
