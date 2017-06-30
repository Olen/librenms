<?php

$version = snmp_get($device, 'jnxVirtualChassisMemberSWVersion.0', '-Oqv', 'JUNIPER-VIRTUALCHASSIS-MIB');

if (strpos($poll_device['sysDescr'], 'olive')) {
    $hardware = 'Olive';
    $serial   = '';
} else {
    $hardware = snmp_get($device, 'sysObjectID.0', '-Ovqs', '+Juniper-Products-MIB:JUNIPER-CHASSIS-DEFINES-MIB', 'junos');
    $hardware = 'Juniper '.rewrite_junos_hardware($hardware);
    $serial   = snmp_get($device, '.1.3.6.1.4.1.2636.3.1.3.0', '-OQv', '+JUNIPER-MIB', 'junos');
}

$features       = '';
