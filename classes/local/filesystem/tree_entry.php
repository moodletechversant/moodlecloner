<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_moodleclone\local\filesystem;

/**
 * One entry found by {@see tree_walker}. Values come from lstat(), so a
 * symlink describes the link itself, never its target.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tree_entry {
    /** @var string Regular file. */
    public const FILE = 'file';

    /** @var string Directory. */
    public const DIRECTORY = 'directory';

    /** @var string Symbolic link whose target stays inside the root. */
    public const SYMLINK = 'symlink';

    /** @var string One of the type constants. */
    public $type;

    /** @var string Path relative to the walked root, forward slashes. */
    public $relative;

    /** @var string Absolute path. */
    public $path;

    /** @var int Size in bytes (0 for directories). */
    public $size = 0;

    /** @var int Permission bits (mode & 07777). */
    public $mode = 0;

    /** @var int Modification time. */
    public $mtime = 0;

    /** @var int Inode change time. */
    public $ctime = 0;

    /** @var int Device number, used to detect a file being swapped between lstat() and fopen(). */
    public $dev = 0;

    /** @var int Inode number, see $dev. */
    public $ino = 0;

    /** @var string|null For symlinks: target relative to the link's directory, normalised. */
    public $linktarget = null;
}
