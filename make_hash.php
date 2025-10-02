<?php
echo password_hash("admin", PASSWORD_DEFAULT);
?>

<!-- -- Extra admin account: pobletecharles11@gmail.com / adminwan
INSERT INTO users (email, password, first_name, last_name, user_type, status) VALUES
('pobletecharles11@gmail.com',
 '$2y$10$N9qo8uLOickgx2ZMRZo5e.uEVa3E6gQ0ucCwQX0kuh.lwBu/6G4i.', -- hash ng 'adminwan'
 'Charles', 'Poblete', 'admin', 'active'); -->
