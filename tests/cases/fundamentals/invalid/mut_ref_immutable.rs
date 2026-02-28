// error: Cannot borrow immutable variable
fn inc(x: &mut i32) {
    *x = *x + 1;
}

fn main() {
    let n: i32 = 5;
    inc(&mut n);
}
