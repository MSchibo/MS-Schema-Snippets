CREATE TABLE tx_siters_queue (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  pid INT DEFAULT 0 NOT NULL,
  page_uid INT NOT NULL,
  mode VARCHAR(12) DEFAULT 'semi' NOT NULL,
  status VARCHAR(12) DEFAULT 'pending' NOT NULL,
  reason VARCHAR(255) DEFAULT '' NOT NULL,
  snippet_json MEDIUMTEXT,
  content_hash VARCHAR(64) DEFAULT '' NOT NULL,
  created_at INT DEFAULT 0 NOT NULL,
  updated_at INT DEFAULT 0 NOT NULL,
  last_error TEXT
) ENGINE=InnoDB;
