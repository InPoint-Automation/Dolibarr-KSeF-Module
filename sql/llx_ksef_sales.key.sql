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
ALTER TABLE llx_ksef_sales ADD UNIQUE INDEX uk_ksef_sales_ksef_number (ksef_number);

CREATE INDEX idx_ksef_sales_invoice_number ON llx_ksef_sales (invoice_number);
CREATE INDEX idx_ksef_sales_invoice_date ON llx_ksef_sales (invoice_date);
CREATE INDEX idx_ksef_sales_fk_facture ON llx_ksef_sales (fk_facture);
CREATE INDEX idx_ksef_sales_link_status ON llx_ksef_sales (link_status);
CREATE INDEX idx_ksef_sales_environment ON llx_ksef_sales (environment);
CREATE INDEX idx_ksef_sales_entity ON llx_ksef_sales (entity);
CREATE INDEX idx_ksef_sales_invoice_hash ON llx_ksef_sales (invoice_hash);
