-- WMS local copy of ERP master data plus its own stock, bins and shipments.
-- erp_ver = last ERP ROW_VER copied by the pull job.
CREATE TABLE items (
    sku         text PRIMARY KEY,
    description text NOT NULL,
    unit_price  numeric(10, 2) NOT NULL,
    on_hand     int NOT NULL CHECK (on_hand >= 0),
    bin         text,
    erp_ver     int NOT NULL DEFAULT 0
);

CREATE TABLE partners (
    erp_ref      text PRIMARY KEY,
    name         text NOT NULL,
    email        text,
    ship_address text,
    ship_city    text,
    erp_ver      int NOT NULL DEFAULT 0
);

CREATE TABLE orders (
    order_no text PRIMARY KEY,
    erp_ref  text NOT NULL,
    status   text NOT NULL DEFAULT 'RECEIVED'
);

CREATE TABLE order_lines (
    order_no text NOT NULL REFERENCES orders,
    sku      text NOT NULL,
    qty      int NOT NULL,
    PRIMARY KEY (order_no, sku)
);

CREATE TABLE shipments (
    order_no    text PRIMARY KEY REFERENCES orders,
    tracking_no text NOT NULL,
    shipped_at  timestamptz NOT NULL DEFAULT now()
);

-- Seeded as "a few months into the legacy integration": mostly in sync, with the usual drift.
INSERT INTO items (sku, description, unit_price, on_hand, bin, erp_ver) VALUES
    ('SKU-1001', 'M8 hex bolt, zinc (box 100)',       12.50,  340, 'A-01-1', 1),
    ('SKU-1002', 'M8 nylon lock nut (box 100)',        8.90,  520, 'A-01-2', 1),
    ('SKU-1003', 'Hydraulic hose 1/2in (10 m)',       64.00,  112, 'B-04-1', 1),  -- cycle count, never posted
    ('SKU-1004', 'Ball bearing 6204-2RS',              4.75,  760, 'A-03-4', 1),  -- SO-5002 shipped
    ('SKU-1005', 'Safety gloves, size L (pair)',       3.20, 1488, 'C-02-1', 1),  -- damaged goods write-off
    ('SKU-1006', 'Pneumatic cylinder 32x100',         89.00,   45, 'B-01-3', 1),
    ('SKU-1007', 'Gear pump GP-20 (discontinued)',   210.00,   35, 'D-09-9', 1),  -- ERP blocks stock postings
    ('SKU-1008', 'Cable tie 300 mm (pack 500)',       15.40,  260, 'A-02-2', 1),
    ('SKU-1009', 'Torque wrench 40-200 Nm',          145.00,   30, 'C-05-1', 1),
    ('SKU-1010', 'Welding rod E6013 2.5 mm (5 kg)',   27.80,   90, 'D-01-1', 1);

INSERT INTO partners (erp_ref, name, email, ship_address, ship_city, erp_ver) VALUES
    ('C-1001', 'Baltic Tooling SRL',           'orders@baltictooling.example', 'Str. Fabricii 12',          'Cluj-Napoca', 1),
    ('C-1002', 'Danube Fasteners GmbH',        'buy@danube-fast.example',      'Industriestr. 4',           'Linz',        1),
    ('C-1003', 'Carpathian Builders SA',       'procurement@carpbuild.example','Bd. Unirii 45',             'Bucharest',   1),
    ('C-1004', 'Nordic Hydraulics AB',         'inkop@nordhyd.example',        'Hamngatan 8, Gate 3 (dock)','Gothenburg',  1),  -- fixed by the warehouse
    ('C-1005', 'Adriatic Marine d.o.o.',       'supply@adrimarine.example',    'Obala 21',                  'Split',       1),
    ('C-1006', 'Iberia Steelworks SL',         'compras@ibsteel.example',      'Calle Mayor 102',           'Bilbao',      1),
    ('C-1007', 'Alpine Robotics AG',           'einkauf@alprobo.example',      'Bahnhofstr. 77',            'Zurich',      1),
    ('C-1008', 'Vistula Logistics Sp. z o.o.', 'zakupy@vislog.example',        'ul. Portowa 3',             'Gdansk',      1);

INSERT INTO orders (order_no, erp_ref, status) VALUES
    ('SO-5001', 'C-1001', 'RECEIVED'),
    ('SO-5002', 'C-1003', 'SHIPPED'),
    ('SO-5003', 'C-1004', 'RECEIVED'),
    ('SO-5004', 'C-1002', 'RECEIVED'),
    ('SO-5005', 'C-1007', 'RECEIVED'),
    ('SO-5006', 'C-1005', 'RECEIVED');  -- the WMS never hears about the ERP cancellation

INSERT INTO order_lines (order_no, sku, qty) VALUES
    ('SO-5001', 'SKU-1001', 10), ('SO-5001', 'SKU-1002', 10),
    ('SO-5002', 'SKU-1004', 40),
    ('SO-5003', 'SKU-1003', 2),  ('SO-5003', 'SKU-1006', 1),
    ('SO-5004', 'SKU-1008', 5),
    ('SO-5005', 'SKU-1009', 2),
    ('SO-5006', 'SKU-1006', 3);

INSERT INTO shipments (order_no, tracking_no, shipped_at) VALUES
    ('SO-5002', 'TRK-88231', now() - interval '2 days');  -- ERP call timed out back then
