// error: *mut pointer requires &mut reference
fn main() {
    let x: i32 = 5;
    let _p: *mut i32 = &x as *mut i32;
}
