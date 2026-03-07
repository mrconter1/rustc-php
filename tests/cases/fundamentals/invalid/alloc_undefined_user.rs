// error: Undefined function 'alloc'

mod alloc;
use crate::alloc;

fn main() {
    let _ = alloc::alloc(8);
}
