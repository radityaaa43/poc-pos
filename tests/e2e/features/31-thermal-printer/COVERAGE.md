# 31-thermal-printer

| Steps | Roles |
|---|---|
| `01-printer-settings`: paper size and auto-print saved and reloaded. `02-webusb-test-print`: fake WebUSB printer, connect, Test Print bytes in 58/80 mm width, drawer kick, disconnect. `03-thermal-receipt`: Thermal button on the receipt sends ESC/POS bytes then the drawer kick; without WebUSB it opens the browser thermal preview. **Manual:** printing on a real USB thermal printer (bytes accepted, cut, drawer pulse) | manager, cashier |
