<?php

namespace App\Support\Media;

use Aws\Signature\S3SignatureV4;

/**
 * SigV4 for presigned uploads that signs Content-Type and Content-Length.
 *
 * The SDK leaves both out of a presigned URL on purpose (content-length is
 * on its header blacklist, content-type on its presign deny list), so a
 * URL "for 2 MB of image/jpeg" accepted any body of any type. Signing them
 * makes R2 recompute the signature with the headers the browser actually
 * sends: a different size or type no longer matches, and the PUT is
 * refused. The browser sets Content-Length from the body itself.
 */
final class LockedPutSignature extends S3SignatureV4
{
    /** @return array<string, bool> */
    protected function getHeaderBlacklist()
    {
        $list = parent::getHeaderBlacklist();
        unset($list['content-length']);

        return $list;
    }

    /** @return array<string, bool> */
    protected function getPresignHeaderDenyList()
    {
        $list = parent::getPresignHeaderDenyList();
        unset($list['content-type']);

        return $list;
    }
}
