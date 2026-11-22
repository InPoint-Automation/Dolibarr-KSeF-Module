-- Copyright (C) 2026 InPoint Automation Sp z o.o.
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU Affero General Public License as
-- published by the Free Software Foundation, either version 3 of the
-- License, or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU Affero General Public License for more details.
--
-- You should have received a copy of the GNU Affero General Public License
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.

CREATE TABLE llx_ksef_nbp_rate_row
(
    rowid                       INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity                      INTEGER DEFAULT 1 NOT NULL,
    fk_multicurrency_rate       INTEGER NOT NULL,
    currency_code               VARCHAR(3) NOT NULL,
    table_day                   DATE NOT NULL,
    nbp_table                   VARCHAR(1),
    table_no                    VARCHAR(32),
    mid                         DOUBLE(24,8) NOT NULL,
    date_fetch                  INTEGER,
    tms                         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
