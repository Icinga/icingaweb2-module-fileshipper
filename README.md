Icinga Web 2 Fileshipper module
===============================

The main purpose of this module is to implement a plain file shipping hook
for Icinga Director. Create a `directories.ini` in this modules config dir,
usually `/etc/icingaweb2/modules/fileshipper`:

```ini
[custom-rules]
source = /usr/local/src/custom-rules.git
target = zones.d/director-global/custom-rules

[test]
source = /tmp/replication-test
target = zones.d/director-global/having-fun
extensions = .conf .md
```

All local files from `source` will be deployed to the given target directory.
Please take care, use of this module requires advanced understanding of Icinga2
configuration. Per default only `.conf` files are synced, you can override this
with a custom space-separated list for the `extensions` parameter.

