# 32-kitchen-display

| Steps | Roles |
|---|---|
| `00-kitchen-intake`: a paid mixed cart sends only the flagged line to the kitchen as accepted; a pending transfer stays out until confirmed paid; a failed and expired payments send nothing, and paying the open bill later (even twice) sends it once (REQ-003 AC-02, AC-05, AC-19 server half; read back through the `kitchen.items` arrange scenario, since there is no kitchen screen yet) | cashier, manager |
