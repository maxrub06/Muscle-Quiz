ALTER TABLE muscario_players
  MODIFY mass DECIMAL(30,2) NOT NULL DEFAULT 12,
  DROP CHECK chk_muscario_mass,
  ADD CONSTRAINT chk_muscario_mass CHECK (mass >= 12);
