<?php

namespace IXP\Utils\Grapher;

/*
 * Copyright (C) 2009 - 2021 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use OSS_SNMP\MIBS\Iface;

use IXP\Services\Grapher\Graph;

/**
 * Naming contract for the Graphite grapher backend.
 *
 * This is the SINGLE SOURCE OF TRUTH for the metric naming scheme shared by:
 *   - the WRITE side (telegraf config generator, `grapher:generate-telegraf-config`),
 *   - the READ side (the future `Graphite` backend's `resolveTarget()`).
 *
 * Both sides MUST build metric leaves via {@see self::metricName()} so that the
 * names telegraf writes to carbon are byte-identical to what the render API is
 * queried for.
 *
 * Metric leaf (WITHOUT the configurable prefix):
 *
 *     switch.<switchId>.port.<ifIndex>.<category>.<direction>
 *
 * e.g. `switch.42.port.5.bits.in`. The prefix (default `ixpmanager`) is applied
 * by telegraf's `outputs.graphite.prefix` on the write side and prepended from
 * `config('grapher.backends.graphite.prefix')` on the read side.
 *
 * IMPORTANT: metrics are RAW SNMP counters (octets/packets/...), not rates. The
 * read side must convert to a rate with graphite
 * `nonNegativeDerivative(<leaf>, <max>)` / `perSecond(<leaf>, <max>)` — see the
 * per-category `max` (counter width) below and the RAW COUNTERS note in the
 * project plan.
 *
 * @author     Andreas Dobloug
 * @category   IXP
 * @package    IXP\Utils\Grapher
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Graphite
{
    /** 64-bit counter max (Counter64) — for wrap handling on ifHC* counters. */
    public const COUNTER64_MAX = '18446744073709551615';

    /** 32-bit counter max (Counter32) — for wrap handling on errs/discs. */
    public const COUNTER32_MAX = '4294967295';

    /**
     * Traffic categories: the contract between telegraf output and the read side.
     *
     * Keyed by `Graph::CATEGORY_*`. Each entry:
     *   - token   : the `<category>` path segment (bits|pkts|errs|discs|bcasts)
     *   - oid_in  : numeric SNMP OID base for the "in" counter (no leading dot)
     *   - oid_out : numeric SNMP OID base for the "out" counter (no leading dot)
     *   - mib_in  : IF-MIB object name for the "in" counter (config comment only)
     *   - mib_out : IF-MIB object name for the "out" counter (config comment only)
     *   - max     : counter width for read-side wrap handling (see COUNTER*_MAX)
     *
     * Pure IF-MIB, uniform across all switches (the ExtremeXOS out-discards
     * vendor-OID substitution used by MRTG is intentionally NOT applied here).
     *
     * @var array<string, array<string, string>>
     */
    public const CATEGORIES = [
        Graph::CATEGORY_BITS => [
            'token'   => 'bits',
            'oid_in'  => Iface::OID_IF_HC_IN_OCTETS,
            'oid_out' => Iface::OID_IF_HC_OUT_OCTETS,
            'mib_in'  => 'ifHCInOctets',
            'mib_out' => 'ifHCOutOctets',
            'max'     => self::COUNTER64_MAX,
        ],
        Graph::CATEGORY_PACKETS => [
            'token'   => 'pkts',
            'oid_in'  => Iface::OID_IF_HC_IN_UNICAST_PACKETS,
            'oid_out' => Iface::OID_IF_HC_OUT_UNICAST_PACKETS,
            'mib_in'  => 'ifHCInUcastPkts',
            'mib_out' => 'ifHCOutUcastPkts',
            'max'     => self::COUNTER64_MAX,
        ],
        Graph::CATEGORY_ERRORS => [
            'token'   => 'errs',
            'oid_in'  => Iface::OID_IF_IN_ERRORS,
            'oid_out' => Iface::OID_IF_OUT_ERRORS,
            'mib_in'  => 'ifInErrors',
            'mib_out' => 'ifOutErrors',
            'max'     => self::COUNTER32_MAX,
        ],
        Graph::CATEGORY_DISCARDS => [
            'token'   => 'discs',
            'oid_in'  => Iface::OID_IF_IN_DISCARDS,
            'oid_out' => Iface::OID_IF_OUT_DISCARDS,
            'mib_in'  => 'ifInDiscards',
            'mib_out' => 'ifOutDiscards',
            'max'     => self::COUNTER32_MAX,
        ],
        Graph::CATEGORY_BROADCASTS => [
            'token'   => 'bcasts',
            'oid_in'  => Iface::OID_IF_HC_IN_BROADCAST,
            'oid_out' => Iface::OID_IF_HC_OUT_BROADCAST,
            'mib_in'  => 'ifHCInBroadcastPkts',
            'mib_out' => 'ifHCOutBroadcastPkts',
            'max'     => self::COUNTER64_MAX,
        ],
    ];

    /** Metric directions. */
    public const DIRECTIONS = [ 'in', 'out' ];

    /**
     * The metric leaf for a single physical switch port (no prefix).
     *
     *     switch.<switchId>.port.<ifIndex>.<category>.<direction>
     *
     * @param int    $switchId  DB id of the switch (stable, not hostname)
     * @param int    $ifIndex   SNMP ifIndex of the port
     * @param string $catToken  category token (e.g. 'bits'); see CATEGORIES[*]['token']
     * @param string $dir       'in' or 'out'
     */
    public static function metricName( int $switchId, int $ifIndex, string $catToken, string $dir ): string
    {
        return "switch.{$switchId}.port.{$ifIndex}.{$catToken}.{$dir}";
    }

    /**
     * Numeric OID with any leading dot stripped (telegraf gosmi wants a bare
     * numeric OID, e.g. `1.3.6.1.2.1.31.1.1.1.6`).
     */
    public static function numericOid( string $oid ): string
    {
        return ltrim( $oid, '.' );
    }
}
