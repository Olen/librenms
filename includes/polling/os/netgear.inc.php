<?php

// sysDescr.0 = XS712T ProSafe 12-Port 10 Gigabit Ethernet (10GbE) Smart Switch, 6.1.0.12, B6.1.0.1
list($hardware, ) = explode(' ', $device['sysDescr']);
list(,$version, ) = explode(',', $device['sysDescr']);
