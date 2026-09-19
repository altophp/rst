# Files

`RstFile` combines an opened source, parse result, editor, and the fingerprint
captured when the file was read.

```php
use Alto\Rst\File\RstFile;
use Alto\Rst\Profile\Profile;

$file = RstFile::open(__DIR__.'/guide.rst', Profile::symfony());
$editor = $file->editor();

// Plan edits with handles from $file->parsed().

echo $file->diff()->toUnifiedString();
$file->save();
```

`save()` compares the current file with the opening fingerprint, writes through
a temporary file in the target directory, preserves permission bits, replaces
the target atomically, and reparses the saved bytes.

External changes raise `FileConflictException` without discarding pending
edits. Symbolic links and non-regular targets are not replaced. `saveAs()`
refuses an existing unopened target by default.

`SaveOptions` can explicitly alter compare-before-write or atomicity. The
application remains responsible for path authorization, backups, and recovery.
See [Security](../security.md) for caller responsibilities.

After a conflict, preserve the pending diff, reopen the current file, and
reapply the intended change to the new source. Review the new diff before
saving. Disabling comparison to force a stale write can discard another
editor's work.
