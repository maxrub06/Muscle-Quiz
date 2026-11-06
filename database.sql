CREATE TABLE IF NOT EXISTS quiz_scores (
  id CHAR(36) NOT NULL PRIMARY KEY,
  player VARCHAR(64) NOT NULL,
  name VARCHAR(20) NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  score SMALLINT UNSIGNED NOT NULL,
  total SMALLINT UNSIGNED NOT NULL,
  pct TINYINT UNSIGNED GENERATED ALWAYS AS (ROUND(score * 100.0 / total)) STORED,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT chk_quiz_scores_level CHECK (level BETWEEN 1 AND 3),
  CONSTRAINT chk_quiz_scores_score CHECK (total > 0 AND score <= total),
  CONSTRAINT chk_quiz_scores_name CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 20),
  INDEX idx_quiz_scores_top (pct DESC, total DESC, created_at ASC),
  INDEX idx_quiz_scores_level_top (level, pct DESC, total DESC, created_at ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS muscario_players (
  player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  name VARCHAR(20) NOT NULL,
  x DECIMAL(8,2) NOT NULL,
  y DECIMAL(8,2) NOT NULL,
  mass DECIMAL(10,2) NOT NULL DEFAULT 12,
  alive TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT chk_muscario_x CHECK (x BETWEEN 0 AND 2800),
  CONSTRAINT chk_muscario_y CHECK (y BETWEEN 0 AND 1900),
  CONSTRAINT chk_muscario_mass CHECK (mass BETWEEN 12 AND 10000),
  CONSTRAINT chk_muscario_alive CHECK (alive IN (0, 1)),
  CONSTRAINT chk_muscario_active CHECK (active IN (0, 1)),
  INDEX idx_muscario_presence (active, alive, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
