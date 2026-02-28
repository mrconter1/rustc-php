// error: not public

mod secret;

use crate::secret::hidden;

fn main() {
    let x = hidden();
}
