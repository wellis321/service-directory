-- Speeds up “recent published grades” sort and graded_within filters.
ALTER TABLE services ADD INDEX idx_grade_published (grade_published);
