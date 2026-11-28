<?php
/**
 * About tab (AboutController): author, links, disclaimer, version and the licence text.
 * @var string $version @var ?string $license the content of the LICENSE file, or null when the file is not on this installation
 */
?>
<h1>About</h1>

<section class="card">
    <h2>Google Ads MCP PHP</h2>
    <dl class="about">
        <dt>Version</dt><dd><?= e($version) ?></dd>
        <dt>Author</dt><dd>Tomasz Kalinowski</dd>
        <dt>Source</dt><dd><a href="https://github.com/t7jk/google-ads-php" target="_blank" rel="noopener noreferrer">https://github.com/t7jk/google-ads-php</a></dd>
        <dt>X</dt><dd><a href="https://x.com/tomas3man" target="_blank" rel="noopener noreferrer">https://x.com/tomas3man</a></dd>
    </dl>
</section>

<section class="card" id="disclaimer">
    <h2>Disclaimer</h2>
    <p>The author has taken every care to make this product work, but takes no responsibility for any damage, including financial loss,
        resulting from changes made to advertising campaigns. You use it at your own risk: always read each plan carefully before you approve it.</p>
    <p>THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
        FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
        LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
        IN THE SOFTWARE.</p>
</section>

<section class="card" id="license">
    <h2>License</h2>
    <?php if ($license !== null): ?>
        <pre class="license"><?= e($license) ?></pre>
    <?php else: ?>
        <p class="muted">The license file is not installed on this server. The software is released under the MIT License; the full text is in the
            <a href="https://github.com/t7jk/google-ads-php" target="_blank" rel="noopener noreferrer">source repository</a>.</p>
    <?php endif; ?>
</section>
