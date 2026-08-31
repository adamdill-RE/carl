-- carl:kind=ddl
-- ZIP -> coordinates -> county (handoff Section 8.3).
-- Public-domain Census ZCTA gazetteer joined to the ZCTA-to-county
-- relationship file. Rows the Zippopotam.us fallback creates carry
-- source='zippopotam' and a NULL county_fips, and are flagged for the admin.
--
-- county_name comes from the Census relationship file and is what the admin
-- 'regions needing research' queue shows for a county with no region row yet.
-- place_name is the city, which only the Zippopotam fallback supplies -- the
-- Census ZCTA files do not carry one.

CREATE TABLE `zcta` (
  `zip`         VARCHAR(10)  NOT NULL,
  `latitude`    DECIMAL(8,5) NOT NULL,
  `longitude`   DECIMAL(8,5) NOT NULL,
  `county_fips` CHAR(5)          NULL,
  `state`       VARCHAR(2)       NULL,
  `county_name` VARCHAR(120)     NULL,
  `place_name`  VARCHAR(120)     NULL,
  `source`      VARCHAR(16)  NOT NULL DEFAULT 'census',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`zip`),
  KEY `idx_zcta_county` (`county_fips`),
  KEY `idx_zcta_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
