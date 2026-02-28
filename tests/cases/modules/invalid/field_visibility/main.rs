// error: private

mod data;

use crate::data::Record;

fn main() {
    let r = Record { value: 10 };
    let x = r.value;
}
