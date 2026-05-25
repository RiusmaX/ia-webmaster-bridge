<?php
/**
 * Critical-path tests for IAWM_Backup — snapshot and restore semantics.
 *
 * The backup module persists rows through the real WordPress `wpdb`
 * surface. Tests use a thin in-memory `WpdbMock` that implements just
 * the methods the backup module touches (insert / update / delete /
 * get_row / get_col / get_results / query / prepare). The mock is
 * reset between cases by the parent test class.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

/**
 * @covers IAWM_Backup
 */
final class IAWM_Test_Backup extends IAWM_Test_Case {

	/**
	 * Snapshot two options, mutate them, restore the backup, and assert
	 * the original values come back. The most-stringent end-to-end test
	 * for the options snapshot lifecycle.
	 *
	 * @return void
	 */
	public function testOptionsSnapshotRoundtrip() {
		update_option( 'foo', 'original_foo' );
		update_option( 'bar', array( 'a', 'b' ) );

		$id = IAWM_Backup::snapshot_options( array( 'foo', 'bar' ), 'Test snapshot' );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		// Mutate the options after the snapshot.
		update_option( 'foo', 'changed' );
		update_option( 'bar', array( 'x' ) );
		$this->assertSame( 'changed', get_option( 'foo' ) );

		// Real restore (not dry-run).
		$result = IAWM_Backup::restore( $id, false, 'iawm_aaaa' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( IAWM_Backup::KIND_OPTIONS, $result['kind'] );
		$this->assertSame( 2, $result['restored'] );

		$this->assertSame( 'original_foo', get_option( 'foo' ), 'Restore must reinstate the original foo' );
		$this->assertSame( array( 'a', 'b' ), get_option( 'bar' ), 'Restore must reinstate the original bar' );
	}

	/**
	 * `snapshot_plugins_state` must capture the live `active_plugins`
	 * list inside the JSON payload so a future restore can replay the
	 * activation state.
	 *
	 * @return void
	 */
	public function testPluginsStateSnapshot() {
		update_option( 'active_plugins', array( 'akismet/akismet.php', 'jetpack/jetpack.php' ) );

		$id = IAWM_Backup::snapshot_plugins_state( 'Snap plugins' );
		$this->assertIsInt( $id );

		// Read the row back including the payload to inspect it.
		$backup = IAWM_Backup::get_backup( $id );
		$this->assertIsArray( $backup );
		$this->assertSame( IAWM_Backup::KIND_PLUGINS, $backup['kind'] );
		$this->assertIsArray( $backup['payload'] );
		$this->assertArrayHasKey( 'active_plugins', $backup['payload'] );
		$this->assertSame(
			array( 'akismet/akismet.php', 'jetpack/jetpack.php' ),
			$backup['payload']['active_plugins']
		);
	}

	/**
	 * Insert 100 backup rows and prune to the newest 50: the next
	 * `list_backups()` call must report exactly 50 remaining, with the
	 * top id intact.
	 *
	 * @return void
	 */
	public function testPruneOldDeletesOldRecords() {
		// Seed 100 lightweight backups so we have a sortable id range.
		for ( $i = 1; $i <= 100; $i++ ) {
			$id = IAWM_Backup::snapshot_options( array( "opt_$i" ), "row #$i" );
			$this->assertIsInt( $id, "Seeded row #$i must yield an id" );
		}

		$deleted = IAWM_Backup::prune_old( 50 );
		$this->assertSame( 50, $deleted, 'Pruning to keep_n=50 from 100 must delete the 50 oldest' );

		$remaining = IAWM_Backup::list_backups( 100, 0 );
		$this->assertCount( 50, $remaining, 'Exactly 50 rows must remain' );
		$this->assertSame( 100, $remaining[0]['id'], 'Newest id must survive the prune' );
		$this->assertSame( 51, end( $remaining )['id'], 'Oldest survivor must be id 51' );
	}
}
