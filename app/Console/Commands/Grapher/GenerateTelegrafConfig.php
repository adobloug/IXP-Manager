<?php

namespace IXP\Console\Commands\Grapher;

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

use Config;
use View;

use IXP\Models\Switcher;

use IXP\Utils\Grapher\Graphite;

/**
 * Artisan command to generate a telegraf configuration for the Graphite grapher
 * backend's WRITE side.
 *
 * telegraf runs on the app host (which has SNMP reach to the switches), walks the
 * per-port IF-MIB counters, and pushes the RAW counters to a remote carbon daemon
 * under the metric scheme defined in {@see \IXP\Utils\Grapher\Graphite}.
 *
 * This is intentionally separate from `grapher:generate-configuration` (which is
 * grapher-backend driven): telegraf is a collector, not a read backend, so it has
 * no `Backend` provider and reads its settings straight from
 * `config('grapher.backends.graphite')`.
 *
 * @author     Andreas Dobloug
 * @category   Grapher
 * @package    IXP\Console\Commands
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class GenerateTelegrafConfig extends GrapherCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grapher:generate-telegraf-config
                        {--O|output=- : Save configuration to specified file (default: stdout)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a telegraf SNMP collector config for the Graphite backend';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if( ( $retval = $this->verifyOutput() ) !== 0 ) {
            return $retval;
        }

        // Switches we can actually poll: active, poll-enabled, with a hostname
        // and an SNMP community. Ports are discovered live by telegraf's table
        // walk, so we only need per-switch connection details here.
        $switches = Switcher::where( 'active', true )
            ->where( 'poll', true )
            ->whereNotNull( 'hostname' )->where( 'hostname', '!=', '' )
            ->whereNotNull( 'snmppasswd' )->where( 'snmppasswd', '!=', '' )
            ->orderBy( 'id' )
            ->get();

        $conf = View::make( 'services.grapher.telegraf.config', [
            'switches'    => $switches,
            'categories'  => Graphite::CATEGORIES,
            'prefix'      => Config::get( 'grapher.backends.graphite.prefix' ),
            'carbonHost'  => Config::get( 'grapher.backends.graphite.carbon_host' ),
            'carbonPort'  => Config::get( 'grapher.backends.graphite.carbon_port' ),
            'interval'    => Config::get( 'grapher.backends.graphite.interval' ),
        ] )->render();

        return $this->outputConfiguration( $conf );
    }

    /**
     * Output the configuration to stdout or the requested file.
     *
     * @return int Suggested status code for script exit (0 == success)
     */
    protected function outputConfiguration( string $conf ): int
    {
        if( $this->option( 'output' ) === '-' ) {
            echo $conf;
            return 0;
        }

        if( !@file_put_contents( $this->option( 'output' ), $conf ) ) {
            $this->error( "Could not save configuration to the specified file [{$this->option('output')}]" );
            return -2;
        }

        return 0;
    }

    /**
     * Verify the --output target is writable (mirrors GenerateConfiguration).
     *
     * @return int 0 for success or else an error code
     */
    protected function verifyOutput(): int
    {
        $fn = $this->option( 'output' );

        if( $fn === '-' ) {
            return 0;
        }

        if( is_file( $fn ) && !is_writable( $fn ) ) {
            $this->error( "The output file exists but is not writable" );
            return 253;
        }

        if( !( dirname( $fn ) && is_dir( dirname( $fn ) ) && is_writable( dirname( $fn ) ) ) ) {
            $this->error( "The output file does not exist and cannot be created" );
            return 252;
        }

        return 0;
    }
}
