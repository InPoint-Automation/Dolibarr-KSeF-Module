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

CREATE TABLE llx_ksef_writeoff
(
    rowid                       INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity                      INTEGER DEFAULT 1 NOT NULL,
    element                     VARCHAR(32) NOT NULL,
    fk_invoice                  INTEGER NOT NULL,
    amount                      DOUBLE(24,8) NOT NULL,
    account                     VARCHAR(32) NOT NULL,
    close_code                  VARCHAR(16),
    date_close                  DATETIME NOT NULL,
    fk_user                     INTEGER,
    tms                         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
