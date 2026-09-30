<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the privacy provider.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;

/**
 * The provider loads and states that the plugin stores no personal data.
 *
 * Loading the class is the test: a get_reason() that does not match the
 * interface is a fatal error the moment the class is loaded.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 * @covers     \local_resourcelinkfix\privacy\provider
 */
final class privacy_provider_test extends advanced_testcase {
    /**
     * The provider is a null provider whose reason is a string of the plugin.
     */
    public function test_provider_states_no_personal_data() {
        // The Privacy API only exists from Moodle 3.4 on.
        if (!interface_exists('\core_privacy\local\metadata\null_provider')) {
            $this->markTestSkipped('No Privacy API in this Moodle version.');
        }

        $this->assertTrue(is_subclass_of(
            '\local_resourcelinkfix\privacy\provider',
            '\core_privacy\local\metadata\null_provider'
        ));
        $reason = \local_resourcelinkfix\privacy\provider::get_reason();
        $this->assertSame('privacy:metadata', $reason);
        $this->assertTrue(get_string_manager()->string_exists($reason, 'local_resourcelinkfix'));
    }
}
