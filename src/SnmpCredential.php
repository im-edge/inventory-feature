<?php

namespace IMEdge\InventoryFeature;

use gipfl\Json\JsonSerialization;
use Ramsey\Uuid\Uuid;

class SnmpCredential implements JsonSerialization
{
    protected array $properties = [];

    public static function fromDbRow($row): SnmpCredential
    {
        $self = new SnmpCredential();
        $self->properties = (array) $row;
        return $self;
    }

    public function jsonSerialize(): object
    {
        $map = [
            'credential_uuid' => 'uuid',
            'credential_name' => 'name',
            'snmp_version'    => 'version',
            'security_name'   => 'securityName',
            'security_level'  => 'securityLevel',
            'auth_protocol'   => 'authProtocol',
            'auth_key'        => 'authKey',
            'priv_protocol'   => 'privProtocol',
            'priv_key'        => 'privKey',
        ];
        $properties = [];
        foreach ($this->properties as $k => $v) {
            if ($k === 'credential_uuid') {
                $v = Uuid::fromBytes($v);
            }
            $properties[$map[$k]] = $v;
        }
        return (object) array_filter($properties, function ($v) {
            return $v !== null;
        });
    }

    public static function fromSerialization($any)
    {
        $map = [
            'credential_uuid' => 'uuid',
            'credential_name' => 'name',
            'snmp_version'    => 'version',
            'security_name'   => 'securityName',
            'security_level'  => 'securityLevel',
            'auth_protocol'   => 'authProtocol',
            'auth_key'        => 'authKey',
            'priv_protocol'   => 'privProtocol',
            'priv_key'        => 'privKey',
        ];
        $properties = [];
        foreach ((array) $any as $k => $v) {
            if ($k === 'uuid') {
                $v = Uuid::fromString($v);
            }
            $properties[$map[$k]] = $v;
        }

        return SnmpCredential::fromDbRow((object) array_filter($properties, function ($v) {
            return $v !== null;
        }));
    }
}
