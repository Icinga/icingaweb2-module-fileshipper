<a id="Installation"></a>Installation
=====================================

## Requirements

* Icinga Director (&gt;= 1.1.0)
* php-xml for optional XML file support
* php-yaml for optional YAML file support
* php-zip for optional XLSX file support

When running `PHP 7.x` you need the latest `2.x beta` version for [php-yaml](http://pecl.php.net/package/yaml).
In case your Linux distribution offers precompiled packages they should be fine, regardless of whether they ship `php-yaml` or `php-syck`. In either case please
let me know as I didn't test them on different operatingsystems yet.

## Install the Fileshipper module

As with any Icinga Web 2 module, installation is pretty straight-forward. In
case you're installing it from source all you have to do is to drop the `fileshipper`
module in one of your module paths. You can examine (and set) the module path(s)
in `Configuration / Application`. In a typical environment you'll probably drop the
module to `/usr/share/icingaweb2/modules/fileshipper`. Please note that the directory
name MUST be `fileshipper` and not `icingaweb2-module-fileshipper` or anything else.

Last but not least go to `Configuration / Modules` and enable the `fileshipper`
module. Otherwise you could also do so on CLI by running:

```sh
icingacli module enable fileshipper
```

That's all, now you are ready to define your first [Import Source](03-ImportSource.md)
definitions and to ship hand-crafted plain Icinga 2 [Config Files](04-FileShipping.md)!
