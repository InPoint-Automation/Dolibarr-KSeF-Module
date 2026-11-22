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

-- Unique Constraints
ALTER TABLE llx_ksef_nbp_rate_row ADD UNIQUE INDEX uk_ksef_nbp_rate_row_day (entity, currency_code, table_day);
ALTER TABLE llx_ksef_nbp_rate_row ADD UNIQUE INDEX uk_ksef_nbp_rate_row_rate (fk_multicurrency_rate);
