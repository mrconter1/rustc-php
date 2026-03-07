// exit: 0
// stdout: 5
fn main() {
    let mut x: i32 = 5;
    let p: *const i32 = &mut x;
    let v = *p;
    println!("{}", v);
}
