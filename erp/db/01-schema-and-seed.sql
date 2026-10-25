-- ERP master data. ver = legacy row version, bumped by every update.
CREATE TABLE customers (
    code    VARCHAR(20) PRIMARY KEY,
    name    VARCHAR(100) NOT NULL,
    email   VARCHAR(100) NOT NULL,
    address VARCHAR(200) NOT NULL,
    city    VARCHAR(60)  NOT NULL,
    ver     INT NOT NULL DEFAULT 1
);

CREATE TABLE products (
    sku         VARCHAR(20) PRIMARY KEY,
    descr       VARCHAR(100) NOT NULL,
    price       DECIMAL(10, 2) NOT NULL,
    qty_on_hand INT NOT NULL DEFAULT 0,
    status      VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
    ver         INT NOT NULL DEFAULT 1
);

CREATE TABLE orders (
    order_no    VARCHAR(20) PRIMARY KEY,
    cust_no     VARCHAR(20) NOT NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'OPEN',
    tracking_no VARCHAR(40),
    ver         INT NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cust_no) REFERENCES customers (code)
);

CREATE TABLE order_lines (
    order_no VARCHAR(20) NOT NULL,
    sku      VARCHAR(20) NOT NULL,
    qty      INT NOT NULL,
    PRIMARY KEY (order_no, sku),
    FOREIGN KEY (order_no) REFERENCES orders (order_no),
    FOREIGN KEY (sku) REFERENCES products (sku)
);

INSERT INTO customers (code, name, email, address, city) VALUES
    ('C-1001', 'Baltic Tooling SRL',          'orders@baltictooling.example', 'Str. Fabricii 12',  'Cluj-Napoca'),
    ('C-1002', 'Danube Fasteners GmbH',       'buy@danube-fast.example',      'Industriestr. 4',   'Linz'),
    ('C-1003', 'Carpathian Builders SA',      'procurement@carpbuild.example','Bd. Unirii 45',     'Bucharest'),
    ('C-1004', 'Nordic Hydraulics AB',        'inkop@nordhyd.example',        'Hamngatan 8',       'Gothenburg'),
    ('C-1005', 'Adriatic Marine d.o.o.',      'supply@adrimarine.example',    'Obala 21',          'Split'),
    ('C-1006', 'Iberia Steelworks SL',        'compras@ibsteel.example',      'Calle Mayor 102',   'Bilbao'),
    ('C-1007', 'Alpine Robotics AG',          'einkauf@alprobo.example',      'Bahnhofstr. 77',    'Zurich'),
    ('C-1008', 'Vistula Logistics Sp. z o.o.','zakupy@vislog.example',        'ul. Portowa 3',     'Gdansk');

INSERT INTO products (sku, descr, price, qty_on_hand, status) VALUES
    ('SKU-1001', 'M8 hex bolt, zinc (box 100)',       12.50,  340, 'ACTIVE'),
    ('SKU-1002', 'M8 nylon lock nut (box 100)',        8.90,  520, 'ACTIVE'),
    ('SKU-1003', 'Hydraulic hose 1/2in (10 m)',       64.00,  120, 'ACTIVE'),
    ('SKU-1004', 'Ball bearing 6204-2RS',              4.75,  800, 'ACTIVE'),
    ('SKU-1005', 'Safety gloves, size L (pair)',       3.20, 1500, 'ACTIVE'),
    ('SKU-1006', 'Pneumatic cylinder 32x100',         89.00,   45, 'ACTIVE'),
    ('SKU-1007', 'Gear pump GP-20 (discontinued)',   210.00,   40, 'BLOCKED'),
    ('SKU-1008', 'Cable tie 300 mm (pack 500)',       15.40,  260, 'ACTIVE'),
    ('SKU-1009', 'Torque wrench 40-200 Nm',          145.00,   30, 'ACTIVE'),
    ('SKU-1010', 'Welding rod E6013 2.5 mm (5 kg)',   27.80,   90, 'ACTIVE');

INSERT INTO orders (order_no, cust_no, status, tracking_no) VALUES
    ('SO-5000', 'C-1006', 'SHIPPED',   'TRK-00001'),
    ('SO-5001', 'C-1001', 'OPEN',      NULL),
    ('SO-5002', 'C-1003', 'OPEN',      NULL),  -- shipped in the WMS, confirmation never arrived
    ('SO-5003', 'C-1004', 'OPEN',      NULL),
    ('SO-5004', 'C-1002', 'OPEN',      NULL),
    ('SO-5005', 'C-1007', 'OPEN',      NULL),
    ('SO-5006', 'C-1005', 'CANCELLED', NULL);  -- cancelled after the WMS already pulled it

INSERT INTO order_lines (order_no, sku, qty) VALUES
    ('SO-5000', 'SKU-1010', 10),
    ('SO-5001', 'SKU-1001', 10), ('SO-5001', 'SKU-1002', 10),
    ('SO-5002', 'SKU-1004', 40),
    ('SO-5003', 'SKU-1003', 2),  ('SO-5003', 'SKU-1006', 1),
    ('SO-5004', 'SKU-1008', 5),
    ('SO-5005', 'SKU-1009', 2),
    ('SO-5006', 'SKU-1006', 3);
