"""TEMPORARY probe v2: time each src/ require AFTER bootstrap.php, writing to a path the web SAPI
can actually write.

Why v2: v1 used write_log(), and app.log is 664 kajagogoo:kajagogoo - the web user is not the owner
and not in that group, so @file_put_contents failed SILENTLY and the probe produced 0 lines.
storage/logs/ is 777, so a NEW file there is creatable by www-data.
"""
import pathlib
import re

target = pathlib.Path("public/index.php")
original = target.read_text(encoding="utf-8")
pathlib.Path("/tmp/index.php.bootprobe.bak").write_text(original, encoding="utf-8")

# Only the requires AFTER bootstrap.php: the two fast-path requires run before it, so their delta
# against a post-bootstrap baseline would come out negative.
split_marker = "require_once __DIR__ . '/../bootstrap.php';\n"
if split_marker not in original:
    raise SystemExit("bootstrap require not found - aborting")
head, tail = original.split(split_marker, 1)

require_re = re.compile(r"^require_once __DIR__ \. '/\.\./src/(.+?)';\s*$")
out_tail = []
names = []
for line in tail.splitlines(keepends=True):
    out_tail.append(line)
    m = require_re.match(line.rstrip("\n"))
    if m:
        name = m.group(1).replace("/", "_").replace(".php", "")
        names.append(name)
        out_tail.append("$kernelBootRequireNs[%r] = hrtime(true);\n" % name)
tail = "".join(out_tail)

anchor = "$kernelBootNsAfterRequires = hrtime(true);\n"
if anchor not in tail:
    raise SystemExit("anchor not found - aborting without writing")
tail = tail.replace(anchor, anchor + """
if (isset($kernelBootRequireNs)) {
    $kernelBootRequirePrevNs = $kernelBootNsAfterBootstrap;
    $kernelBootRequireDeltas = [];
    foreach ($kernelBootRequireNs as $kernelBootRequireName => $kernelBootRequireValueNs) {
        $kernelBootRequireDeltas[$kernelBootRequireName] =
            round(($kernelBootRequireValueNs - $kernelBootRequirePrevNs) / 1_000_000, 3);
        $kernelBootRequirePrevNs = $kernelBootRequireValueNs;
    }
    @file_put_contents(
        STORAGE_PATH . '/logs/bootprobe.jsonl',
        json_encode($kernelBootRequireDeltas) . "\\n",
        FILE_APPEND | LOCK_EX
    );
}
""", 1)

target.write_text(head + split_marker + tail, encoding="utf-8")
print(f"patched, {len(names)} requires timed (after bootstrap.php):")
print("  " + ", ".join(names))
