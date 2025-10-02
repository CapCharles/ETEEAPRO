<?php
echo password_hash("admin", PASSWORD_DEFAULT);
?>

<!-- -- Extra admin account: pobletecharles11@gmail.com / adminwan
INSERT INTO users (email, password, first_name, last_name, user_type, status) VALUES
('pobletecharles11@gmail.com',
 '$2y$10$pWjB8gWdBfC7nBdhgPSINutpCaVIN/Oe9HJ8Fqxbs5GK5BMts2yIO', -- hash ng 'adminwan'
 'Charles', 'Poblete', 'admin', 'active'); -->
