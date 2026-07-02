<?php

namespace Tests\Services\Grapher\Backends;

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

use ReflectionMethod;
use ReflectionProperty;

use Carbon\Carbon;

use Illuminate\Support\Facades\Http;

use IXP\Services\Grapher as GrapherService;
use IXP\Services\Grapher\Graph;
use IXP\Services\Grapher\Graph\IXP as IXPGraph;
use IXP\Services\Grapher\Backend\Graphite as GraphiteBackend;

use Tests\TestCase;

/**
 * PHPUnit tests for the DB-free logic of the Graphite grapher backend:
 * target-expression building (the read/write naming contract), the query time
 * window, the JSON→contract row mapping, and render-API response parsing.
 *
 * Entity membership / `resolveLeaves()` needs a populated DB and is exercised by
 * the live verification step, not here.
 *
 * @author     Andreas Dobloug
 * @category   IXP
 * @package    IXP\Tests\Services\Grapher\Backends
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class GraphiteTest extends TestCase
{
    private GraphiteBackend $backend;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // pin config so the contract assertions are deterministic
        config( [
            'grapher.backends.graphite.prefix'     => 'ixpmanager',
            'grapher.backends.graphite.render_url' => 'http://graphite.example.com',
            'grapher.backends.graphite.timeout'    => 5,
        ] );

        $this->backend = new GraphiteBackend;
    }

    /**
     * Build a bare graph (IXP needs no DB model) with a set category/period.
     */
    private function graph( string $category = Graph::CATEGORY_BITS, string $period = Graph::PERIOD_DAY ): IXPGraph
    {
        $g = new IXPGraph( app( GrapherService::class ) );
        $g->setCategory( $category )->setPeriod( $period );
        return $g;
    }

    /**
     * Invoke a private/protected method on the backend.
     */
    private function invoke( string $method, array $args ): mixed
    {
        $m = new ReflectionMethod( $this->backend, $method );
        $m->setAccessible( true );
        return $m->invokeArgs( $this->backend, $args );
    }

    /**
     * Seed the backend's in-process port-catalog memo so catalogLeaves() can be
     * exercised without a database (portCatalog() returns the memo as-is).
     *
     * @param array<int, array{sw:int, if:int, cust:int, loc:int, infra:int, rf:bool}> $rows
     */
    private function seedCatalog( array $rows ): void
    {
        $p = new ReflectionProperty( GraphiteBackend::class, 'catalog' );
        $p->setAccessible( true );
        $p->setValue( null, $rows );
    }

    #[\Override]
    protected function tearDown(): void
    {
        // static memo persists across tests in one process — reset it
        $p = new ReflectionProperty( GraphiteBackend::class, 'catalog' );
        $p->setAccessible( true );
        $p->setValue( null, null );

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // targetExpr() — the naming contract + counter-wrap handling
    // ---------------------------------------------------------------------

    public function testTargetExprSingleLeafBits(): void
    {
        $expr = $this->invoke( 'targetExpr', [ $this->graph(), [ [ 'sw' => 42, 'if' => 5 ] ], 'in', 'average' ] );

        // Counter64 max for bits; leaf byte-identical to Graphite::metricName() / telegraf output
        $this->assertSame(
            "consolidateBy(sumSeries(perSecond(ixpmanager.switch.42.port.5.bits.in,18446744073709551615)),'average')",
            $expr
        );
    }

    public function testTargetExprMultiLeafErrorsUsesCounter32AndMax(): void
    {
        $expr = $this->invoke( 'targetExpr', [
            $this->graph( Graph::CATEGORY_ERRORS ),
            [ [ 'sw' => 7, 'if' => 3 ], [ 'sw' => 7, 'if' => 4 ] ],
            'out',
            'max',
        ] );

        $this->assertSame(
            "consolidateBy(sumSeries("
                . "perSecond(ixpmanager.switch.7.port.3.errs.out,4294967295),"
                . "perSecond(ixpmanager.switch.7.port.4.errs.out,4294967295)"
                . "),'max')",
            $expr
        );
    }

    // ---------------------------------------------------------------------
    // window() — query time window
    // ---------------------------------------------------------------------

    public function testWindowFixedPeriodSpansMrtgPeriodTime(): void
    {
        [ $from, $until ] = $this->invoke( 'window', [ $this->graph( Graph::CATEGORY_BITS, Graph::PERIOD_DAY ) ] );

        $this->assertIsInt( $from );
        $this->assertIsInt( $until );
        // day period = 119988s (Mrtg::PERIOD_TIME[day])
        $this->assertSame( 119988, $until - $from );
    }

    public function testWindowCustomWithoutBoundsReturnsNulls(): void
    {
        $g = $this->graph();
        // force a custom period with no start/end
        $g->setPeriod( Graph::PERIOD_CUSTOM );

        [ $from, $until ] = $this->invoke( 'window', [ $g ] );

        $this->assertNull( $from );
        $this->assertNull( $until );
    }

    public function testWindowCustomUsesGraphBounds(): void
    {
        $start = Carbon::createFromTimestamp( 1_600_000_000 );
        $end   = Carbon::createFromTimestamp( 1_600_086_400 );

        $g = $this->graph();
        $g->setPeriod( Graph::PERIOD_CUSTOM, $start, $end );

        [ $from, $until ] = $this->invoke( 'window', [ $g ] );

        $this->assertSame( 1_600_000_000, $from );
        $this->assertSame( 1_600_086_400, $until );
    }

    // ---------------------------------------------------------------------
    // rows() — JSON datapoints → grapher contract rows
    // ---------------------------------------------------------------------

    public function testRowsScalesBitsAndZeroesNulls(): void
    {
        // graphite datapoints are [value, ts], oldest first
        $series = [
            'avgin'  => [ [ 100.0, 1000 ], [ 200.0, 1060 ] ],
            'avgout' => [ [ 10.0,  1000 ], [ null,  1060 ] ],
            'maxin'  => [ [ 150.0, 1000 ], [ 250.0, 1060 ] ],
            'maxout' => [ [ 15.0,  1000 ], [ 20.0,  1060 ] ],
        ];

        $rows = $this->invoke( 'rows', [ $this->graph( Graph::CATEGORY_BITS ), $series ] );

        // bits => x8; null => 0; ts from avgin; order preserved
        $this->assertSame( [
            [ 1000, 800, 80, 1200, 120 ],
            [ 1060, 1600, 0, 2000, 160 ],
        ], $rows );
    }

    public function testRowsPacketsAreNotScaled(): void
    {
        $series = [
            'avgin'  => [ [ 100.0, 1000 ] ],
            'avgout' => [ [ 10.0,  1000 ] ],
            'maxin'  => [ [ 150.0, 1000 ] ],
            'maxout' => [ [ 15.0,  1000 ] ],
        ];

        $rows = $this->invoke( 'rows', [ $this->graph( Graph::CATEGORY_PACKETS ), $series ] );

        $this->assertSame( [ [ 1000, 100, 10, 150, 15 ] ], $rows );
    }

    // ---------------------------------------------------------------------
    // renderJson() — render API response parsing
    // ---------------------------------------------------------------------

    public function testRenderJsonMapsSeriesByAlias(): void
    {
        Http::fake( [ '*' => Http::response( [
            [ 'target' => 'avgin',  'datapoints' => [ [ 1.5, 1000 ] ] ],
            [ 'target' => 'avgout', 'datapoints' => [ [ 2.5, 1000 ] ] ],
        ], 200 ) ] );

        $out = $this->invoke( 'renderJson', [ 1000, 2000, [ 'avgin' => 'X', 'avgout' => 'Y' ] ] );

        $this->assertSame( [
            'avgin'  => [ [ 1.5, 1000 ] ],
            'avgout' => [ [ 2.5, 1000 ] ],
        ], $out );
    }

    public function testRenderJsonReturnsNullOnHttpError(): void
    {
        Http::fake( [ '*' => Http::response( 'boom', 500 ) ] );

        $out = $this->invoke( 'renderJson', [ 1000, 2000, [ 'avgin' => 'X' ] ] );

        $this->assertNull( $out );
    }

    // ---------------------------------------------------------------------
    // supports() — capability matrix
    // ---------------------------------------------------------------------

    public function testSupportsOmitsRrdAndPerVlanAndTrunk(): void
    {
        $s = GraphiteBackend::supports();

        // covered entities
        foreach( [ 'ixp', 'infrastructure', 'location', 'switcher', 'corebundle',
                   'physicalinterface', 'virtualinterface', 'customer' ] as $t ) {
            $this->assertArrayHasKey( $t, $s );
            $this->assertArrayNotHasKey( Graph::TYPE_RRD, $s[ $t ][ 'types' ], "$t must not offer rrd" );
        }

        // out of scope for graphite
        foreach( [ 'vlan', 'vlaninterface', 'p2p', 'multip2p', 'trunk' ] as $t ) {
            $this->assertArrayNotHasKey( $t, $s );
        }

        // aggregates: bits + pkts only; detailed: all categories
        $this->assertSame( [ Graph::CATEGORY_BITS, Graph::CATEGORY_PACKETS ], array_values( $s[ 'ixp' ][ 'categories' ] ) );
        $this->assertSame( Graph::CATEGORIES, $s[ 'customer' ][ 'categories' ] );
    }

    // ---------------------------------------------------------------------
    // catalogLeaves() — in-memory filtering of the port catalog
    // ---------------------------------------------------------------------

    /** A small fixture catalog exercising every grouping key + the rf flag. */
    private function fixtureCatalog(): array
    {
        return [
            [ 'sw' => 1, 'if' => 10, 'cust' => 100, 'loc' => 5, 'infra' => 2, 'rf' => false ],
            [ 'sw' => 1, 'if' => 11, 'cust' => 100, 'loc' => 5, 'infra' => 2, 'rf' => true  ], // reseller/fanout
            [ 'sw' => 2, 'if' => 20, 'cust' => 101, 'loc' => 6, 'infra' => 2, 'rf' => false ],
            [ 'sw' => 3, 'if' => 30, 'cust' => 100, 'loc' => 5, 'infra' => 3, 'rf' => false ],
        ];
    }

    public function testCatalogLeavesProjectsSwIfOnly(): void
    {
        $this->seedCatalog( $this->fixtureCatalog() );

        $leaves = $this->invoke( 'catalogLeaves', [ static fn( array $r ): bool => $r[ 'sw' ] === 1 && !$r[ 'rf' ] ] );

        // switcher predicate: only the non-rf port on switch 1, projected to sw/if
        $this->assertSame( [ [ 'sw' => 1, 'if' => 10 ] ], $leaves );
    }

    public function testCatalogLeavesIxpExcludesResellerFanout(): void
    {
        $this->seedCatalog( $this->fixtureCatalog() );

        $leaves = $this->invoke( 'catalogLeaves', [ static fn( array $r ): bool => !$r[ 'rf' ] ] );

        // the rf port (sw1/if11) is dropped; all others kept
        $this->assertSame( [
            [ 'sw' => 1, 'if' => 10 ],
            [ 'sw' => 2, 'if' => 20 ],
            [ 'sw' => 3, 'if' => 30 ],
        ], $leaves );
    }

    public function testCatalogLeavesLocationAndInfraFilters(): void
    {
        $this->seedCatalog( $this->fixtureCatalog() );

        $loc = $this->invoke( 'catalogLeaves', [ static fn( array $r ): bool => $r[ 'loc' ] === 5 && !$r[ 'rf' ] ] );
        $this->assertSame( [ [ 'sw' => 1, 'if' => 10 ], [ 'sw' => 3, 'if' => 30 ] ], $loc );

        $infra = $this->invoke( 'catalogLeaves', [ static fn( array $r ): bool => $r[ 'infra' ] === 2 && !$r[ 'rf' ] ] );
        $this->assertSame( [ [ 'sw' => 1, 'if' => 10 ], [ 'sw' => 2, 'if' => 20 ] ], $infra );
    }

    public function testCatalogLeavesCustomerRespectsResellerFanoutFlag(): void
    {
        $this->seedCatalog( $this->fixtureCatalog() );

        // exclude=true (default): customer 100 without their rf port
        $excluded = $this->invoke( 'catalogLeaves',
            [ static fn( array $r ): bool => $r[ 'cust' ] === 100 && !( true && $r[ 'rf' ] ) ] );
        $this->assertSame( [ [ 'sw' => 1, 'if' => 10 ], [ 'sw' => 3, 'if' => 30 ] ], $excluded );

        // exclude=false (Mrtg-compatible): customer 100 including their rf port
        $included = $this->invoke( 'catalogLeaves',
            [ static fn( array $r ): bool => $r[ 'cust' ] === 100 && !( false && $r[ 'rf' ] ) ] );
        $this->assertSame( [ [ 'sw' => 1, 'if' => 10 ], [ 'sw' => 1, 'if' => 11 ], [ 'sw' => 3, 'if' => 30 ] ], $included );
    }
}
