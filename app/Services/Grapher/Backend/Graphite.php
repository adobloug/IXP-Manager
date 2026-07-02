<?php

namespace IXP\Services\Grapher\Backend;

/*
 * Copyright (C) 2009 - 2026 Internet Neutral Exchange Association Company Limited By Guarantee.
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

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use IXP\Contracts\Grapher\Backend as GrapherBackendContract;

use IXP\Exceptions\Services\Grapher\CannotHandleRequestException;

use IXP\Models\{
    Customer,
    PhysicalInterface
};

use IXP\Services\Grapher\Backend as GrapherBackend;
use IXP\Services\Grapher\Graph;

use IXP\Utils\Grapher\{
    Graphite as GraphiteNaming,
    Mrtg as MrtgUtil
};

/**
 * Grapher Backend -> Graphite
 *
 * READ side of the remote-Graphite grapher backend. Queries a remote
 * graphite-web render API for traffic data (JSON) and images (PNG proxy).
 *
 * Metrics are RAW SNMP counters written by the local telegraf collector under
 * the naming scheme in {@see \IXP\Utils\Grapher\Graphite}. This backend converts
 * them to per-second rates at query time with graphite `perSecond(leaf, max)`
 * (nonNegativeDerivative + counter-wrap handling), aggregates entity members
 * with `sumSeries()`, and consolidates with `consolidateBy()`. The order matters:
 *
 *     perSecond (per leaf) -> sumSeries -> consolidateBy -> (x8 for bits, in PHP)
 *
 * Aggregates are built from EXPLICIT peering-port leaf lists from the DB (no
 * `port.*` wildcards) because shared switches carry non-peering interconnect
 * ports that must not be counted.
 *
 * @author     Andreas Dobloug
 * @category   Grapher
 * @package    IXP\Services\Grapher
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Graphite extends GrapherBackend implements GrapherBackendContract
{
    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function name(): string
    {
        return 'graphite';
    }

    /**
     * The read backend needs no IXP-Manager-generated configuration; the
     * collector config is produced separately by `grapher:generate-telegraf-config`.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function isConfigurationRequired(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function isMonolithicConfigurationSupported(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function isMultiFileConfigurationSupported(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @return string[]
     *
     * @psalm-return array<never, never>
     */
    #[\Override]
    public function generateConfiguration( int $type = self::GENERATED_CONFIG_TYPE_MONOLITHIC, array $options = [] ): array
    {
        return [];
    }

    /**
     * Get a complete list of functionality that this backend supports.
     *
     * Mirrors the Mrtg matrix minus RRD (graphite never serves rrd) and minus the
     * per-VLAN / per-peer types (vlan, vlaninterface, p2p, multip2p) which are not
     * derivable from per-port SNMP — those stay on the sflow backend.
     *
     * NB: `trunk` is intentionally omitted for now: trunk definitions
     * (config/grapher_trunks.php) carry no port membership, so the member-port
     * leaves cannot be resolved from the DB yet. TODO once a trunk model exists.
     *
     * {@inheritDoc}
     *
     * @return string[][][]
     */
    #[\Override]
    public static function supports(): array
    {
        $types = Graph::TYPES;
        unset( $types[ Graph::TYPE_RRD ] );

        // aggregate views: bits + packets only (parity with Mrtg; matches authorise())
        $aggregate = [
            'protocols'   => [ Graph::PROTOCOL_ALL => Graph::PROTOCOL_ALL ],
            'categories'  => [ Graph::CATEGORY_BITS    => Graph::CATEGORY_BITS,
                               Graph::CATEGORY_PACKETS => Graph::CATEGORY_PACKETS ],
            'periods'     => Graph::PERIODS,
            'types'       => $types,
        ];

        // per-port / member / core views: all categories
        $detailed = [
            'protocols'   => [ Graph::PROTOCOL_ALL => Graph::PROTOCOL_ALL ],
            'categories'  => Graph::CATEGORIES,
            'periods'     => Graph::PERIODS,
            'types'       => $types,
        ];

        return [
            'ixp'               => $aggregate,
            'infrastructure'    => $aggregate,
            'location'          => $aggregate,
            'switcher'          => $aggregate,
            'corebundle'        => $detailed,
            'physicalinterface' => $detailed,
            'virtualinterface'  => $detailed,
            'customer'          => $detailed,
        ];
    }

    /**
     * Get the data points for a given graph.
     *
     * Returns the standard grapher contract: an array of
     * `[ ts, avgIn, avgOut, maxIn, maxOut ]` rows, oldest first. Rates are
     * per-second; `bits` is returned in bits/sec (x8 from stored octets).
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function data( Graph $graph ): array
    {
        try {
            $leaves = $this->resolveLeaves( $graph );
        } catch( CannotHandleRequestException $e ) {
            Log::notice( "[Grapher] {$this->name()} data(): {$e->getMessage()}" );
            return [];
        }

        if( $leaves === [] ) {
            return [];
        }

        [ $from, $until ] = $this->window( $graph );
        if( $from === null || $until === null ) {
            Log::notice( "[Grapher] {$this->name()} data(): custom period without start/end" );
            return [];
        }

        // Four aliased targets in one request; aliases let us map the response
        // back regardless of the order graphite returns them in.
        $targets = [
            'avgin'  => $this->targetExpr( $graph, $leaves, 'in',  'average' ),
            'avgout' => $this->targetExpr( $graph, $leaves, 'out', 'average' ),
            'maxin'  => $this->targetExpr( $graph, $leaves, 'in',  'max' ),
            'maxout' => $this->targetExpr( $graph, $leaves, 'out', 'max' ),
        ];

        $series = $this->renderJson( $from, $until, $targets );
        if( $series === null ) {
            return [];
        }

        return $this->rows( $graph, $series );
    }

    /**
     * Get the PNG image for a given graph (proxied from the graphite render API).
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function png( Graph $graph ): false|string
    {
        try {
            $leaves = $this->resolveLeaves( $graph );
        } catch( CannotHandleRequestException $e ) {
            Log::notice( "[Grapher] {$this->name()} png(): {$e->getMessage()}" );
            return '';
        }

        if( $leaves === [] ) {
            return '';
        }

        [ $from, $until ] = $this->window( $graph );
        if( $from === null || $until === null ) {
            return '';
        }

        $mult = $graph->category() === Graph::CATEGORY_BITS ? 8 : 1;
        $scale = static fn( string $t ): string => $mult === 8 ? "scale({$t},8)" : $t;

        // Native graphite look (intentionally NOT a rrdtool clone): translucent
        // filled areas for in/out, native legend + gridlines. Peaks live in the
        // JSON/data() contract; the image stays clean with avg in/out only.
        $params = [
            'format'    => 'png',
            'from'      => $from,
            'until'     => $until,
            'width'     => 790,
            'height'    => 300,
            'yMin'      => 0,
            'title'     => $graph->title(),
            'vtitle'    => $graph->category() . ' / second',
            'areaMode'  => 'all',
            'areaAlpha' => 0.3,
            'lineWidth' => 1.5,
        ];

        // optional server-side render theme (e.g. NAV-style template=nav)
        if( $tpl = config( 'grapher.backends.graphite.png_template' ) ) {
            $params[ 'template' ] = $tpl;
        }

        $qs = http_build_query( $params );
        $qs .= '&target=' . rawurlencode( "color(alias({$scale( $this->targetExpr( $graph, $leaves, 'in',  'average' ) )},'In'),'1f77b4')" );
        $qs .= '&target=' . rawurlencode( "color(alias({$scale( $this->targetExpr( $graph, $leaves, 'out', 'average' ) )},'Out'),'2ca02c')" );

        try {
            $resp = Http::timeout( (int)config( 'grapher.backends.graphite.timeout', 5 ) )
                ->get( $this->renderUrl() . '?' . $qs );

            if( !$resp->successful() ) {
                Log::notice( "[Grapher] {$this->name()} png(): render API returned HTTP {$resp->status()}" );
                return '';
            }

            return $resp->body();
        } catch( \Throwable $e ) {
            Log::notice( "[Grapher] {$this->name()} png(): render API request failed: {$e->getMessage()}" );
            return '';
        }
    }

    /**
     * Graphite serves no rrd files.
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function rrd( Graph $graph ): false|string
    {
        return '';
    }

    /**
     * Get the render target expression for a given graph (for debugging).
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function dataPath( Graph $graph ): string
    {
        try {
            $leaves = $this->resolveLeaves( $graph );
        } catch( CannotHandleRequestException $e ) {
            return '';
        }

        if( $leaves === [] ) {
            return '';
        }

        return $this->targetExpr( $graph, $leaves, 'in', 'average' );
    }

    /**
     * Resolve the explicit list of peering-port metric leaves for a graph's entity.
     *
     * Each leaf is `[ 'sw' => switchId, 'if' => ifIndex ]`. Category and direction
     * are appended later by {@see self::targetExpr()}.
     *
     * @return array<int, array{sw:int, if:int}>
     *
     * @throws CannotHandleRequestException
     */
    private function resolveLeaves( Graph $graph ): array
    {
        switch( $graph->classType() ) {

            case 'PhysicalInterface':
                /** @var Graph\PhysicalInterface $graph */
                return $this->leavesFromPis( [ $graph->physicalInterface() ] );

            case 'VirtualInterface':
                /** @var Graph\VirtualInterface $graph */
                return $this->leavesFromPis( $graph->virtualInterface()->physicalInterfaces->all() );

            case 'Customer':
                /** @var Graph\Customer $graph */
                $id = $graph->customer()->id;
                return $this->leavesFromPis( array_filter( $this->peeringPis(),
                    static fn( PhysicalInterface $pi ): bool => $pi->virtualInterface->customer->id === $id ) );

            case 'Switcher':
                /** @var Graph\Switcher $graph */
                $id = $graph->switch()->id;
                return $this->leavesFromPis( array_filter( $this->peeringPis(),
                    static fn( PhysicalInterface $pi ): bool => $pi->switchPort->switcher->id === $id ) );

            case 'Location':
                /** @var Graph\Location $graph */
                $id = $graph->location()->id;
                return $this->leavesFromPis( array_filter( $this->peeringPis(),
                    static fn( PhysicalInterface $pi ): bool => $pi->switchPort->switcher->cabinet->location->id === $id ) );

            case 'Infrastructure':
                /** @var Graph\Infrastructure $graph */
                $id = $graph->infrastructure()->id;
                return $this->leavesFromPis( array_filter( $this->peeringPis(),
                    static fn( PhysicalInterface $pi ): bool => $pi->switchPort->switcher->infrastructureModel->id === $id ) );

            case 'IXP':
                return $this->leavesFromPis( $this->peeringPis() );

            case 'CoreBundle':
                /** @var Graph\CoreBundle $graph */
                return $this->coreBundleLeaves( $graph );

            default:
                throw new CannotHandleRequestException(
                    "Backend asserted it could process but cannot handle graph of type: {$graph->type()}" );
        }
    }

    /**
     * All connected, pollable, peering physical interfaces across the IXP.
     *
     * Mirrors the customer walk in {@see Mrtg::getPeeringPorts()}: skips core-bundle
     * VIs, disconnected ports, ports with no ifIndex, ports on inactive/unpolled
     * switches, and reseller/fanout ports. This is the single source of the
     * peering-only membership used by all aggregate graphs.
     *
     * @return array<int, PhysicalInterface>
     */
    private function peeringPis(): array
    {
        $pis = [];

        foreach( Customer::all() as $c ) {
            foreach( $c->virtualInterfaces as $vi ) {
                // core bundle interfaces have their own CoreBundle graphs
                if( $vi->getCoreBundle() !== false ) {
                    continue;
                }

                /** @var PhysicalInterface $pi */
                foreach( $vi->physicalInterfaces as $pi ) {
                    if( !$pi->isConnectedOrQuarantine()
                        || !$pi->switchPort->ifIndex
                        || !( $pi->switchPort->switcher->active && $pi->switchPort->switcher->poll )
                    ) {
                        continue;
                    }

                    // don't count reseller or fanout ports in aggregates
                    if( $pi->switchPort->typeReseller() || $pi->switchPort->typeFanout() ) {
                        continue;
                    }

                    $pis[] = $pi;
                }
            }
        }

        return $pis;
    }

    /**
     * Build core-bundle leaves for the requested side (a|b).
     *
     * @return array<int, array{sw:int, if:int}>
     */
    private function coreBundleLeaves( Graph\CoreBundle $graph ): array
    {
        $side   = $graph->side();
        $leaves = [];

        foreach( $graph->coreBundle()->corelinks as $cl ) {
            $ci = $side === 'a' ? $cl->coreInterfaceSideA : $cl->coreInterfaceSideB;
            $pi = $ci?->physicalInterface;

            if( $pi && $pi->switchPort && $pi->switchPort->ifIndex ) {
                $leaves[] = [ 'sw' => $pi->switchPort->switcher->id, 'if' => (int)$pi->switchPort->ifIndex ];
            }
        }

        return $leaves;
    }

    /**
     * Convert physical interfaces to metric leaves, skipping any without a
     * usable switch/ifIndex.
     *
     * @param array<int, PhysicalInterface> $pis
     *
     * @return array<int, array{sw:int, if:int}>
     */
    private function leavesFromPis( array $pis ): array
    {
        $leaves = [];

        foreach( $pis as $pi ) {
            if( !$pi->switchPort || !$pi->switchPort->ifIndex ) {
                continue;
            }
            $leaves[] = [ 'sw' => $pi->switchPort->switcher->id, 'if' => (int)$pi->switchPort->ifIndex ];
        }

        return $leaves;
    }

    /**
     * Build a graphite render target expression for the given direction and
     * consolidation over an explicit leaf list.
     *
     *     consolidateBy(sumSeries( perSecond(<leaf>,<max>), ... ), '<consol>')
     *
     * @param array<int, array{sw:int, if:int}> $leaves
     * @param string $dir    'in'|'out'
     * @param string $consol 'average'|'max'
     */
    private function targetExpr( Graph $graph, array $leaves, string $dir, string $consol ): string
    {
        $cat    = GraphiteNaming::CATEGORIES[ $graph->category() ];
        $token  = $cat[ 'token' ];
        $max    = $cat[ 'max' ];
        $prefix = config( 'grapher.backends.graphite.prefix' );

        $series = [];
        foreach( $leaves as $l ) {
            $leaf     = $prefix . '.' . GraphiteNaming::metricName( $l[ 'sw' ], $l[ 'if' ], $token, $dir );
            $series[] = "perSecond({$leaf},{$max})";
        }

        return "consolidateBy(sumSeries(" . implode( ',', $series ) . "),'{$consol}')";
    }

    /**
     * Resolve the [from, until] unix-timestamp window for a graph.
     *
     * Mirrors {@see Rrd::data()}: fixed periods map through Mrtg::PERIOD_TIME;
     * custom periods use the graph's start/end. Returns [null, null] if a custom
     * period is missing its bounds.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function window( Graph $graph ): array
    {
        if( $graph->period() === Graph::PERIOD_CUSTOM ) {
            if( !$graph->periodStart() || !$graph->periodEnd() ) {
                return [ null, null ];
            }
            return [ (int)$graph->periodStart()->timestamp, (int)$graph->periodEnd()->timestamp ];
        }

        $until = time();
        $from  = $until - (int)MrtgUtil::PERIOD_TIME[ $graph->period() ];
        return [ $from, $until ];
    }

    /**
     * Query the render API for JSON and return series keyed by their alias.
     *
     * @param array<string, string> $targets alias => target expression
     *
     * @return array<string, array<int, array{0: float|null, 1: int}>>|null
     *         alias => datapoints ([value, ts]); null on HTTP/parse failure
     */
    private function renderJson( int $from, int $until, array $targets ): ?array
    {
        $qs = http_build_query( [ 'format' => 'json', 'from' => $from, 'until' => $until ] );
        foreach( $targets as $alias => $expr ) {
            $qs .= '&target=' . rawurlencode( "alias({$expr},'{$alias}')" );
        }

        try {
            $resp = Http::timeout( (int)config( 'grapher.backends.graphite.timeout', 5 ) )
                ->get( $this->renderUrl() . '?' . $qs );

            if( !$resp->successful() ) {
                Log::notice( "[Grapher] {$this->name()} data(): render API returned HTTP {$resp->status()}" );
                return null;
            }

            $json = $resp->json();
            if( !is_array( $json ) ) {
                Log::notice( "[Grapher] {$this->name()} data(): render API returned non-array JSON" );
                return null;
            }
        } catch( \Throwable $e ) {
            Log::notice( "[Grapher] {$this->name()} data(): render API request failed: {$e->getMessage()}" );
            return null;
        }

        $out = [];
        foreach( $json as $s ) {
            if( isset( $s[ 'target' ], $s[ 'datapoints' ] ) ) {
                $out[ $s[ 'target' ] ] = $s[ 'datapoints' ];
            }
        }
        return $out;
    }

    /**
     * Assemble the grapher contract rows from the four aliased graphite series.
     *
     * @param array<string, array<int, array{0: float|null, 1: int}>> $series
     *
     * @return array<int, array{0:int,1:int,2:int,3:int,4:int}>
     */
    private function rows( Graph $graph, array $series ): array
    {
        $avgIn  = $series[ 'avgin' ]  ?? [];
        $avgOut = $series[ 'avgout' ] ?? [];
        $maxIn  = $series[ 'maxin' ]  ?? [];
        $maxOut = $series[ 'maxout' ] ?? [];

        $mult = $graph->category() === Graph::CATEGORY_BITS ? 8 : 1;

        $val = static fn( array $dp, int $i ): int =>
            isset( $dp[ $i ][ 0 ] ) && $dp[ $i ][ 0 ] !== null ? (int)round( $dp[ $i ][ 0 ] * $mult ) : 0;

        $rows = [];
        // graphite returns oldest-first and all four series share the same
        // from/until/step, so datapoints align index-for-index.
        foreach( $avgIn as $i => $dp ) {
            $ts = (int)( $dp[ 1 ] ?? 0 );
            $rows[] = [
                $ts,
                $val( $avgIn,  $i ),
                $val( $avgOut, $i ),
                $val( $maxIn,  $i ),
                $val( $maxOut, $i ),
            ];
        }

        return $rows;
    }

    /**
     * The full render endpoint URL (base render_url + /render).
     */
    private function renderUrl(): string
    {
        return rtrim( (string)config( 'grapher.backends.graphite.render_url' ), '/' ) . '/render';
    }
}
