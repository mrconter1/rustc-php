// exit: 0
// stdout: 7

fn id(x: i32) -> i32 { x }

fn main() {
    let r: &i32 = &id(7);
    println!("{}", *r);
}
