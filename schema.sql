-- schema.sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  funds_available DECIMAL(14,2) DEFAULT 100000,
  is_admin TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE trades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  entry_date DATE NOT NULL,
  close_date DATE DEFAULT NULL,
  symbol VARCHAR(50),
  marketcap ENUM('Large','Mid','Small') DEFAULT 'Mid',
  position_percent DECIMAL(7,4) DEFAULT 0,
  entry_price DECIMAL(14,4),
  stop_loss DECIMAL(14,4),
  target_price DECIMAL(14,4),
  exit_price DECIMAL(14,4),
  outcome VARCHAR(50) DEFAULT 'OPEN',
  pl_percent DECIMAL(10,4) DEFAULT NULL,
  rr DECIMAL(10,4) DEFAULT NULL,
  allocation_amount DECIMAL(14,2) DEFAULT NULL,
  points INT DEFAULT 0,
  analysis_link TEXT,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE rules (
  id INT PRIMARY KEY,
  key_name VARCHAR(50) UNIQUE,
  value_num DECIMAL(12,4)
);

INSERT INTO rules (id, key_name, value_num) VALUES
(1,'profit_points',10),
(2,'sl_analysis_points',5),
(3,'no_sl_penalty',-10),
(4,'min_rr',2),
(5,'rr_bonus',5),
(6,'consistency_points',15);

CREATE INDEX idx_trades_user_close ON trades(user_id, close_date);
