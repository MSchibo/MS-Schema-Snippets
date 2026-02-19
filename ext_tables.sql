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

CREATE TABLE tx_siterichsnippets_item (
  uid int(11) NOT NULL AUTO_INCREMENT,
  pid int(11) DEFAULT '0' NOT NULL,

  tstamp int(11) DEFAULT '0' NOT NULL,
  crdate int(11) DEFAULT '0' NOT NULL,
  cruser_id int(11) DEFAULT '0' NOT NULL,
  deleted tinyint(4) DEFAULT '0' NOT NULL,
  hidden tinyint(4) DEFAULT '0' NOT NULL,
  sorting int(11) DEFAULT '0' NOT NULL,

  active tinyint(1) DEFAULT '1' NOT NULL,

  type varchar(64) DEFAULT '' NOT NULL,
  variant varchar(64) DEFAULT '' NOT NULL,

  config mediumtext,
  data mediumtext,

  hash char(40) DEFAULT '' NOT NULL,

  PRIMARY KEY (uid),
  KEY parent (pid),
  KEY type (type),
  KEY active (active),
  KEY hidden (hidden),
  KEY deleted (deleted),
  KEY hash (hash)
) ENGINE=InnoDB;
