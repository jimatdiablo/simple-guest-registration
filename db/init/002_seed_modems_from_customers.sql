-- If a customers table is loaded by a provided SQL export, mirror it into modems.
-- This enables use of source-like schema while keeping app reads on the local modems table.
INSERT INTO modems (
  id, first, last, account, stNum, street, unit, city, state, zip,
  phone, phone2, node, sg, profile, status, mac, mtamac, mtafile,
  username1, displayname1, login1, pass1, username2, displayname2,
  login2, pass2, oldprofile, notes, vdate
)
SELECT
  c.id, c.first, c.last, c.account, c.stNum, c.street, c.unit, c.city, c.state, c.zip,
  c.phone, c.phone2, c.node, c.sg, c.profile, c.status, c.mac, c.mtamac, c.mtafile,
  c.username1, c.displayname1, c.login1, c.pass1, c.username2, c.displayname2,
  c.login2, c.pass2, c.oldprofile, c.notes, c.vdate
FROM customers c
WHERE NOT EXISTS (
  SELECT 1 FROM modems m WHERE m.id = c.id
);
